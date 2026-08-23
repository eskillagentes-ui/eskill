<?php

declare(strict_types=1);

/**
 * 🧾 Ficha Técnica - Dashboard
 * Interface completa para gerenciamento de atributos ML
 */

$pageTitle = 'Ficha Técnica - SEO';
$currentPage = 'tech-sheet';
$activePage = 'tech-sheet';

// SSR KPIs so first paint (and Cursor DOM picker) match live stats without waiting for JS
$tsSsrStats = [
    'total_items' => '-',
    'critical_gap_items' => '-',
    'hidden_gap_items' => '-',
    'model_label' => '-',
    'pending_suggestions_total' => '-',
    'avg_completeness_label' => '-',
];
$tsSsrAccountId = (int)($_SESSION['active_ml_account_id'] ?? $_SESSION['account_id'] ?? 0);
if ($tsSsrAccountId > 0) {
    try {
        $tsSsrRaw = (new \App\Services\TechSheetService($tsSsrAccountId))->stats([]);
        $tsModel = $tsSsrRaw['model_suggestions'] ?? [];
        $tsAvg = $tsSsrRaw['avg_completeness_analyzed'] ?? null;
        $tsSsrStats = [
            'total_items' => (string)(int)($tsSsrRaw['total_items'] ?? 0),
            'critical_gap_items' => (string)(int)($tsSsrRaw['critical_gap_items'] ?? 0),
            'hidden_gap_items' => (string)(int)($tsSsrRaw['hidden_gap_items'] ?? 0),
            'model_label' => ((int)($tsModel['items_with_suggestions'] ?? 0)) . '/' . ((int)($tsModel['items_needing_model'] ?? 0)),
            'pending_suggestions_total' => (string)(int)($tsSsrRaw['pending_suggestions_total'] ?? 0),
            'avg_completeness_label' => $tsAvg !== null ? number_format((float)$tsAvg, 1, '.', '') . '%' : '-',
        ];
    } catch (\Throwable $e) {
        // keep placeholders; JS loadStats will fill
    }
}

// Account Selector
$requireAccountSelection = true;
$showAccountBanner = true;
include __DIR__ . '/../../components/account-selector.php';

// Page Header
$title = '🧾 Ficha Técnica';
$subtitle = 'Gerenciamento de Atributos do Mercado Livre';
include __DIR__ . '/../../layouts/modern/partials/page-header.php';
?>

<style>
    :root {
        --ts-primary: #5e60ce;
        --ts-secondary: #48bfe3;
        --ts-success: #06d6a0;
        --ts-warning: #ffd166;
        --ts-danger: #ef476f;
        --ts-purple: #8b5cf6;
        --ts-gradient: linear-gradient(135deg, #5e60ce, #48bfe3);
    }

    /* Button: outline-purple */
    .btn-outline-purple {
        color: var(--ts-purple);
        border-color: var(--ts-purple);
        background: transparent;
    }

    .btn-outline-purple:hover {
        color: #fff;
        background-color: var(--ts-purple);
        border-color: var(--ts-purple);
    }

    .text-purple {
        color: var(--ts-purple) !important;
    }

    .ts-header {
        background: var(--ts-gradient);
        color: white;
        padding: 1.5rem 2rem;
        border-radius: 16px;
        margin-bottom: 1.5rem;
        position: relative;
        overflow: hidden;
    }

    .ts-header::before {
        content: '🧾';
        position: absolute;
        right: 20px;
        top: 50%;
        transform: translateY(-50%);
        font-size: 64px;
        opacity: 0.15;
    }

    .ts-header h1 {
        margin: 0;
        font-size: 1.75rem;
        font-weight: 700;
    }

    .kpi-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
        gap: 1rem;
        margin-bottom: 1.5rem;
    }

    .kpi-card {
        background: white;
        border-radius: 12px;
        padding: 1.25rem;
        box-shadow: 0 2px 12px rgba(0, 0, 0, 0.06);
        text-align: center;
        transition: transform 0.2s, box-shadow 0.2s;
    }

    .kpi-card:hover {
        transform: translateY(-2px);
        box-shadow: 0 4px 20px rgba(0, 0, 0, 0.1);
    }

    .kpi-card .value {
        font-size: 2rem;
        font-weight: 700;
        color: var(--ts-primary);
    }

    .kpi-card .label {
        color: #6c757d;
        font-size: 0.85rem;
        margin-top: 0.25rem;
    }

    .kpi-card.danger .value {
        color: var(--ts-danger);
    }

    .kpi-card.warning .value {
        color: var(--ts-warning);
    }

    .kpi-card.success .value {
        color: var(--ts-success);
    }

    .kpi-card.purple .value {
        color: var(--ts-purple);
    }

    .kpi-card.purple {
        border-left: 4px solid var(--ts-purple);
    }

    .filters-bar {
        background: white;
        border-radius: 12px;
        padding: 1rem 1.25rem;
        margin-bottom: 1rem;
        box-shadow: 0 2px 8px rgba(0, 0, 0, 0.04);
        display: flex;
        flex-wrap: wrap;
        gap: 0.75rem;
        align-items: center;
    }

    .filters-bar .form-control,
    .filters-bar .form-select {
        max-width: 200px;
        font-size: 0.9rem;
    }

    .tab-pills {
        display: flex;
        gap: 0.5rem;
        margin-bottom: 1rem;
        flex-wrap: wrap;
    }

    .tab-pill {
        padding: 0.625rem 1.25rem;
        border: none;
        background: #f0f0f5;
        border-radius: 25px;
        font-weight: 600;
        font-size: 0.875rem;
        cursor: pointer;
        transition: all 0.3s ease;
        display: inline-flex;
        align-items: center;
        gap: 0.5rem;
        position: relative;
        overflow: hidden;
    }

    .tab-pill::before {
        content: '';
        position: absolute;
        top: 0;
        left: 0;
        right: 0;
        bottom: 0;
        background: var(--ts-gradient);
        opacity: 0;
        transition: opacity 0.3s ease;
        z-index: 0;
    }

    .tab-pill span,
    .tab-pill .badge,
    .tab-pill i {
        position: relative;
        z-index: 1;
    }

    .tab-pill:hover:not(.active) {
        transform: translateY(-2px);
        box-shadow: 0 4px 12px rgba(94, 96, 206, 0.2);
    }

    .tab-pill:hover:not(.active)::before {
        opacity: 0.1;
    }

    .tab-pill.active {
        background: var(--ts-gradient);
        color: white;
        box-shadow: 0 4px 15px rgba(94, 96, 206, 0.35);
        transform: translateY(-1px);
    }

    .tab-pill.active::before {
        opacity: 1;
    }

    .tab-pill .badge {
        font-size: 0.7rem;
        padding: 0.25rem 0.6rem;
        border-radius: 12px;
        background: rgba(94, 96, 206, 0.15);
        color: var(--ts-primary);
        font-weight: 700;
        transition: all 0.3s ease;
    }

    .tab-pill.active .badge {
        background: rgba(255, 255, 255, 0.25);
        color: white;
    }

    .tab-pill:hover:not(.active) .badge {
        background: rgba(94, 96, 206, 0.25);
    }

    .items-table-container {
        background: white;
        border-radius: 12px;
        box-shadow: 0 2px 12px rgba(0, 0, 0, 0.06);
        overflow: hidden;
    }

    .items-table {
        width: 100%;
        border-collapse: collapse;
    }

    .items-table thead {
        background: #f8f9fa;
    }

    .items-table th {
        padding: 0.875rem 1rem;
        text-align: left;
        font-weight: 600;
        font-size: 0.85rem;
        color: #495057;
        border-bottom: 2px solid #e9ecef;
    }

    .items-table td {
        padding: 0.875rem 1rem;
        border-bottom: 1px solid #f0f0f5;
        vertical-align: middle;
    }

    .items-table tbody tr:hover {
        background: #f8f9ff;
    }

    .completeness-bar {
        width: 100%;
        height: 8px;
        background: #e9ecef;
        border-radius: 4px;
        overflow: hidden;
        position: relative;
    }

    .completeness-bar .fill {
        height: 100%;
        border-radius: 4px;
        transition: width 0.3s;
    }

    .completeness-bar .fill.critical {
        background: var(--ts-danger);
    }

    .completeness-bar .fill.warning {
        background: var(--ts-warning);
    }

    .completeness-bar .fill.good {
        background: var(--ts-success);
    }

    /* SEO Score Mini Badge */
    .seo-score-mini {
        width: 36px;
        height: 36px;
        border-radius: 8px;
        display: flex;
        align-items: center;
        justify-content: center;
        font-weight: 700;
        font-size: 0.8rem;
        flex-shrink: 0;
    }

    .seo-score-mini.excellent {
        background: linear-gradient(135deg, #06d6a0, #38ef7d);
        color: white;
    }

    .seo-score-mini.good {
        background: linear-gradient(135deg, #56ab2f, #a8e063);
        color: white;
    }

    .seo-score-mini.warning {
        background: linear-gradient(135deg, #f7971e, #ffd200);
        color: #333;
    }

    .seo-score-mini.critical {
        background: linear-gradient(135deg, #ef476f, #f45c43);
        color: white;
    }

    /* Item Meta */
    .item-meta {
        display: flex;
        align-items: center;
        gap: 0.5rem;
        margin-top: 0.25rem;
        font-size: 0.75rem;
    }

    .category-badge {
        background: #e9ecef;
        padding: 0.1rem 0.4rem;
        border-radius: 4px;
        font-size: 0.7rem;
        color: #6c757d;
    }

    /* Gaps Container */
    .gaps-container {
        display: flex;
        flex-wrap: wrap;
        gap: 0.25rem;
    }

    .complete-badge {
        display: inline-flex;
        align-items: center;
        gap: 0.25rem;
        padding: 0.25rem 0.5rem;
        border-radius: 6px;
        font-size: 0.75rem;
        font-weight: 600;
        background: rgba(6, 214, 160, 0.15);
        color: #059669;
    }

    /* Row with hidden gap highlight */
    .items-table tbody tr.has-hidden-gap {
        background: rgba(138, 43, 226, 0.02);
    }

    .items-table tbody tr.has-hidden-gap:hover {
        background: rgba(138, 43, 226, 0.05);
    }

    /* Suggestions Container */
    .suggestions-container {
        display: flex;
        flex-wrap: wrap;
        gap: 0.25rem;
    }

    .gaps-badge {
        display: inline-flex;
        align-items: center;
        gap: 0.25rem;
        padding: 0.25rem 0.5rem;
        border-radius: 6px;
        font-size: 0.75rem;
        font-weight: 600;
    }

    .gaps-badge.critical {
        background: rgba(239, 71, 111, 0.1);
        color: var(--ts-danger);
    }

    .gaps-badge.high {
        background: rgba(255, 209, 102, 0.15);
        color: #d49a00;
    }

    .gaps-badge.medium {
        background: rgba(72, 191, 227, 0.1);
        color: var(--ts-secondary);
    }

    .action-buttons {
        display: flex;
        gap: 0.5rem;
    }

    /* ===== BOTÕES DE AÇÃO MODERNOS ===== */
    .btn-action {
        padding: 0.5rem 1rem;
        border-radius: 10px;
        font-size: 0.8rem;
        font-weight: 600;
        border: none;
        cursor: pointer;
        transition: all 0.3s ease;
        position: relative;
        overflow: hidden;
        display: inline-flex;
        align-items: center;
        gap: 0.4rem;
    }

    .btn-action::before {
        content: '';
        position: absolute;
        top: 0;
        left: -100%;
        width: 100%;
        height: 100%;
        background: linear-gradient(90deg, transparent, rgba(255, 255, 255, 0.3), transparent);
        transition: left 0.5s ease;
        z-index: 0;
        pointer-events: none;
    }

    /* Garantir que o conteúdo do botão fique visível */
    .btn-action i,
    .btn-action .fas,
    .btn-action .fa,
    .btn-action .bi,
    .btn-action span {
        position: relative;
        z-index: 1;
    }

    .btn-action:hover::before {
        left: 100%;
    }

    .btn-action i {
        transition: transform 0.3s ease;
    }

    .btn-action:hover i {
        transform: scale(1.15);
    }

    .btn-action.primary {
        background: var(--ts-gradient);
        color: white;
        box-shadow: 0 4px 15px rgba(94, 96, 206, 0.35);
    }

    .btn-action.primary:hover {
        transform: translateY(-2px);
        box-shadow: 0 6px 20px rgba(94, 96, 206, 0.45);
    }

    .btn-action.secondary {
        background: #f0f0f5;
        color: #495057;
        border: 1px solid #e0e0e5;
    }

    .btn-action.secondary:hover {
        background: #e8e8ed;
        transform: translateY(-1px);
    }

    .btn-action.success {
        background: linear-gradient(135deg, #06d6a0, #00b894);
        color: white;
        box-shadow: 0 4px 15px rgba(6, 214, 160, 0.35);
    }

    .btn-action.success:hover {
        transform: translateY(-2px);
        box-shadow: 0 6px 20px rgba(6, 214, 160, 0.45);
    }

    .btn-action.warning {
        background: linear-gradient(135deg, #ffd166, #f7971e);
        color: #333;
        box-shadow: 0 4px 15px rgba(255, 209, 102, 0.4);
    }

    .btn-action.warning:hover {
        transform: translateY(-2px);
        box-shadow: 0 6px 20px rgba(255, 209, 102, 0.5);
    }

    .btn-action.danger {
        background: linear-gradient(135deg, #ef476f, #e63946);
        color: white;
        box-shadow: 0 4px 15px rgba(239, 71, 111, 0.35);
    }

    .btn-action.danger:hover {
        transform: translateY(-2px);
        box-shadow: 0 6px 20px rgba(239, 71, 111, 0.45);
    }

    /* Botão Gradient Purple especial */
    .btn-gradient-purple {
        background: linear-gradient(135deg, #8b5cf6 0%, #a855f7 50%, #c084fc 100%) !important;
        color: white !important;
        border: none !important;
        box-shadow: 0 4px 15px rgba(139, 92, 246, 0.4);
        position: relative;
        overflow: hidden;
    }

    .btn-gradient-purple::before {
        content: '';
        position: absolute;
        top: 0;
        left: -100%;
        width: 100%;
        height: 100%;
        background: linear-gradient(90deg, transparent, rgba(255, 255, 255, 0.25), transparent);
        transition: left 0.6s ease;
        z-index: 0;
        pointer-events: none;
    }

    /* Garantir que o conteúdo do btn-gradient-purple fique visível */
    .btn-gradient-purple i,
    .btn-gradient-purple .fas,
    .btn-gradient-purple .fa,
    .btn-gradient-purple .bi,
    .btn-gradient-purple span {
        position: relative;
        z-index: 1;
    }

    .btn-gradient-purple:hover::before {
        left: 100%;
    }

    .btn-gradient-purple:hover {
        transform: translateY(-2px);
        box-shadow: 0 8px 25px rgba(139, 92, 246, 0.5);
        color: white !important;
    }

    /* Botões na Filters Bar */
    .filters-bar .btn {
        border-radius: 10px;
        font-weight: 500;
        padding: 0.5rem 1rem;
        transition: all 0.3s ease;
        position: relative;
        overflow: hidden;
        display: inline-flex;
        align-items: center;
        gap: 0.4rem;
    }

    .filters-bar .btn::after {
        content: '';
        position: absolute;
        top: 50%;
        left: 50%;
        width: 0;
        height: 0;
        background: rgba(255, 255, 255, 0.2);
        border-radius: 50%;
        transform: translate(-50%, -50%);
        transition: width 0.3s, height 0.3s;
        z-index: 0;
        pointer-events: none;
    }

    .filters-bar .btn:hover::after {
        width: 150px;
        height: 150px;
    }

    .filters-bar .btn:hover {
        transform: translateY(-2px);
    }

    /* Garantir que ícones e texto fiquem visíveis */
    .filters-bar .btn i,
    .filters-bar .btn .fas,
    .filters-bar .btn .fa,
    .filters-bar .btn .bi {
        position: relative;
        z-index: 1;
        transition: transform 0.3s ease;
    }

    .filters-bar .btn:hover i,
    .filters-bar .btn:hover .fas {
        transform: rotate(15deg) scale(1.1);
    }

    .filters-bar .btn-outline-primary:hover i,
    .filters-bar .btn-outline-primary:hover .fas {
        animation: spin-once 0.5s ease;
    }

    @keyframes spin-once {
        from {
            transform: rotate(0deg);
        }

        to {
            transform: rotate(360deg);
        }
    }

    /* ===== BULK BAR MODERNA ===== */
    .bulk-bar {
        position: fixed;
        bottom: 0;
        left: 0;
        right: 0;
        background: linear-gradient(180deg, rgba(255, 255, 255, 0.98) 0%, rgba(248, 249, 250, 0.98) 100%);
        backdrop-filter: blur(10px);
        border-top: 1px solid rgba(94, 96, 206, 0.15);
        padding: 1rem 2rem;
        display: none;
        align-items: center;
        justify-content: space-between;
        box-shadow: 0 -8px 30px rgba(94, 96, 206, 0.12);
        z-index: 1000;
        animation: slideUp 0.3s ease;
    }

    @keyframes slideUp {
        from {
            transform: translateY(100%);
            opacity: 0;
        }

        to {
            transform: translateY(0);
            opacity: 1;
        }
    }

    .bulk-bar.active {
        display: flex;
    }

    .bulk-bar .selected-info {
        font-weight: 700;
        color: var(--ts-primary);
        font-size: 1rem;
        display: flex;
        align-items: center;
        gap: 0.5rem;
        background: rgba(94, 96, 206, 0.08);
        padding: 0.5rem 1rem;
        border-radius: 10px;
    }

    .bulk-bar .selected-info::before {
        content: '✓';
        display: inline-flex;
        width: 24px;
        height: 24px;
        background: var(--ts-gradient);
        color: white;
        border-radius: 50%;
        align-items: center;
        justify-content: center;
        font-size: 0.75rem;
        font-weight: bold;
    }

    .bulk-bar .bulk-actions {
        display: flex;
        gap: 0.5rem;
        align-items: center;
        flex-wrap: wrap;
    }

    /* Botões na Bulk Bar */
    .bulk-bar .btn {
        border-radius: 10px;
        font-weight: 600;
        padding: 0.5rem 0.875rem;
        font-size: 0.8rem;
        transition: all 0.3s ease;
        position: relative;
        overflow: hidden;
        display: inline-flex;
        align-items: center;
        gap: 0.4rem;
    }

    .bulk-bar .btn::before {
        content: '';
        position: absolute;
        top: 0;
        left: -100%;
        width: 100%;
        height: 100%;
        background: linear-gradient(90deg, transparent, rgba(255, 255, 255, 0.25), transparent);
        transition: left 0.5s ease;
        z-index: 0;
        pointer-events: none;
    }

    /* Garantir que ícones e texto da bulk bar fiquem visíveis */
    .bulk-bar .btn i,
    .bulk-bar .btn .fas,
    .bulk-bar .btn .fa,
    .bulk-bar .btn .bi,
    .bulk-bar .btn span {
        position: relative;
        z-index: 1;
    }

    .bulk-bar .btn:hover::before {
        left: 100%;
    }

    .bulk-bar .btn:hover {
        transform: translateY(-2px);
    }

    .bulk-bar .btn i,
    .bulk-bar .btn .fas {
        transition: transform 0.3s ease;
    }

    .bulk-bar .btn:hover i,
    .bulk-bar .btn:hover .fas {
        transform: scale(1.15);
    }

    /* Botão principal de Aplicar */
    .bulk-bar .btn-success {
        background: linear-gradient(135deg, #06d6a0 0%, #00b894 100%);
        border: none;
        box-shadow: 0 4px 15px rgba(6, 214, 160, 0.35);
    }

    .bulk-bar .btn-success:hover {
        box-shadow: 0 6px 20px rgba(6, 214, 160, 0.5);
    }

    /* Botão Rocket (Bulk SEO) */
    .bulk-bar .btn-warning {
        background: linear-gradient(135deg, #ffc107 0%, #ff9800 100%);
        border: none;
        color: #333;
        box-shadow: 0 4px 15px rgba(255, 193, 7, 0.35);
    }

    .bulk-bar .btn-warning:hover {
        box-shadow: 0 6px 20px rgba(255, 193, 7, 0.5);
        color: #333;
    }

    .bulk-bar .btn-warning:hover i {
        animation: rocket-launch 0.5s ease;
    }

    @keyframes rocket-launch {

        0%,
        100% {
            transform: translateY(0) rotate(0);
        }

        50% {
            transform: translateY(-4px) rotate(-10deg);
        }
    }

    /* Select na bulk bar */
    .bulk-bar .form-select {
        border-radius: 10px;
        border: 2px solid #e9ecef;
        font-weight: 500;
        transition: all 0.3s ease;
    }

    .bulk-bar .form-select:focus {
        border-color: var(--ts-primary);
        box-shadow: 0 0 0 3px rgba(94, 96, 206, 0.15);
    }

    /* Drawer (detalhe do item) */
    .item-drawer {
        position: fixed;
        top: 0;
        right: -500px;
        width: 500px;
        max-width: 90vw;
        height: 100vh;
        background: white;
        box-shadow: -4px 0 30px rgba(0, 0, 0, 0.15);
        z-index: 1050;
        transition: right 0.3s ease;
        display: flex;
        flex-direction: column;
    }

    .item-drawer.open {
        right: 0;
    }

    .drawer-backdrop {
        position: fixed;
        top: 0;
        left: 0;
        right: 0;
        bottom: 0;
        background: rgba(0, 0, 0, 0.4);
        backdrop-filter: blur(4px);
        z-index: 1040;
        display: none;
        animation: fadeIn 0.2s ease;
    }

    @keyframes fadeIn {
        from {
            opacity: 0;
        }

        to {
            opacity: 1;
        }
    }

    .drawer-backdrop.open {
        display: block;
    }

    .drawer-header {
        padding: 1.25rem 1.5rem;
        background: var(--ts-gradient);
        color: white;
        display: flex;
        align-items: center;
        justify-content: space-between;
    }

    .drawer-header h5 {
        margin: 0;
        font-size: 1.1rem;
        font-weight: 600;
    }

    .drawer-header .btn-close {
        filter: brightness(0) invert(1);
        opacity: 0.8;
        transition: all 0.2s ease;
    }

    .drawer-header .btn-close:hover {
        opacity: 1;
        transform: rotate(90deg);
    }

    .drawer-body {
        flex: 1;
        overflow-y: auto;
        padding: 1.25rem;
    }

    .drawer-footer {
        padding: 1rem 1.5rem;
        background: linear-gradient(180deg, rgba(248, 249, 250, 0) 0%, rgba(248, 249, 250, 1) 100%);
        border-top: 1px solid #e9ecef;
        display: flex;
        gap: 0.75rem;
        justify-content: flex-end;
    }

    /* Botões do Drawer */
    .drawer-footer .btn {
        border-radius: 10px;
        font-weight: 600;
        padding: 0.625rem 1.25rem;
        transition: all 0.3s ease;
        position: relative;
        overflow: hidden;
        display: inline-flex;
        align-items: center;
        gap: 0.5rem;
    }

    .drawer-footer .btn::before {
        content: '';
        position: absolute;
        top: 0;
        left: -100%;
        width: 100%;
        height: 100%;
        background: linear-gradient(90deg, transparent, rgba(255, 255, 255, 0.25), transparent);
        transition: left 0.5s ease;
    }

    .drawer-footer .btn:hover::before {
        left: 100%;
    }

    .drawer-footer .btn-outline-secondary {
        border: 2px solid #dee2e6;
    }

    .drawer-footer .btn-outline-secondary:hover {
        background: #f8f9fa;
        transform: translateY(-1px);
    }

    .drawer-footer .btn-outline-primary {
        border: 2px solid var(--ts-primary);
        color: var(--ts-primary);
    }

    .drawer-footer .btn-outline-primary:hover {
        background: rgba(94, 96, 206, 0.08);
        transform: translateY(-2px);
    }

    .drawer-footer .btn-outline-primary:hover i {
        animation: magic-sparkle 0.5s ease;
    }

    @keyframes magic-sparkle {

        0%,
        100% {
            transform: rotate(0) scale(1);
        }

        25% {
            transform: rotate(-15deg) scale(1.2);
        }

        75% {
            transform: rotate(15deg) scale(1.1);
        }
    }

    .drawer-footer .btn-success {
        background: linear-gradient(135deg, #06d6a0 0%, #00b894 100%);
        border: none;
        color: white;
        box-shadow: 0 4px 15px rgba(6, 214, 160, 0.35);
    }

    .drawer-footer .btn-success:hover {
        transform: translateY(-2px);
        box-shadow: 0 6px 20px rgba(6, 214, 160, 0.5);
    }

    .drawer-footer .btn-success:hover i {
        animation: check-bounce 0.4s ease;
    }

    @keyframes check-bounce {

        0%,
        100% {
            transform: scale(1);
        }

        50% {
            transform: scale(1.3);
        }
    }

    .gap-item {
        background: #f8f9fa;
        border-radius: 10px;
        padding: 1rem;
        margin-bottom: 0.75rem;
        border-left: 4px solid var(--ts-primary);
    }

    .gap-item.critical {
        border-left-color: var(--ts-danger);
    }

    .gap-item.high {
        border-left-color: var(--ts-warning);
    }

    .gap-item .gap-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 0.5rem;
    }

    .gap-item .gap-name {
        font-weight: 600;
        font-size: 0.9rem;
    }

    .suggestion-card {
        background: #f0f8ff;
        border-radius: 8px;
        padding: 0.75rem;
        margin-top: 0.5rem;
    }

    .suggestion-card .suggestion-value {
        font-weight: 600;
        color: var(--ts-primary);
    }

    .suggestion-card .suggestion-meta {
        font-size: 0.75rem;
        color: #6c757d;
        margin-top: 0.25rem;
    }

    .confidence-badge {
        display: inline-flex;
        align-items: center;
        gap: 0.25rem;
        padding: 0.2rem 0.5rem;
        border-radius: 12px;
        font-size: 0.7rem;
        font-weight: 600;
    }

    .confidence-badge.high {
        background: rgba(6, 214, 160, 0.15);
        color: #059669;
    }

    .confidence-badge.medium {
        background: rgba(255, 209, 102, 0.2);
        color: #d49a00;
    }

    .confidence-badge.low {
        background: rgba(239, 71, 111, 0.1);
        color: var(--ts-danger);
    }

    /* Hidden SEO Attributes Styles */
    .gaps-badge.hidden {
        background: rgba(138, 43, 226, 0.1);
        color: #8b2be2;
        border: 1px solid rgba(138, 43, 226, 0.2);
    }

    .gap-item.hidden {
        border-left-color: #8b2be2;
    }

    .hidden-seo-section {
        background: linear-gradient(135deg, rgba(138, 43, 226, 0.05), rgba(75, 0, 130, 0.05));
        border: 1px solid rgba(138, 43, 226, 0.2);
        border-radius: 12px;
        padding: 1rem;
        margin-top: 1rem;
    }

    .hidden-seo-badge {
        display: inline-flex;
        align-items: center;
        gap: 0.3rem;
        background: linear-gradient(135deg, #8b2be2, #4b0082);
        color: white;
        padding: 0.2rem 0.6rem;
        border-radius: 12px;
        font-size: 0.7rem;
        font-weight: 600;
    }

    .hidden-attr-card {
        background: white;
        border: 1px solid rgba(138, 43, 226, 0.15);
        border-left: 3px solid #8b2be2;
        border-radius: 8px;
        padding: 0.75rem;
        margin-bottom: 0.5rem;
    }

    .hidden-attr-card .attr-name {
        font-weight: 600;
        color: #333;
    }

    .hidden-attr-card .seo-impact {
        font-size: 0.75rem;
        color: #8b2be2;
        margin-top: 0.25rem;
    }

    .kpi-card.hidden .value {
        color: #8b2be2;
    }

    /* Quick Actions Dropdown */
    .action-dropdown {
        position: relative;
        display: inline-block;
    }

    .action-dropdown-btn {
        padding: 0.375rem 0.5rem;
        border-radius: 6px;
        border: none;
        background: #f0f0f5;
        cursor: pointer;
        font-size: 0.8rem;
        transition: all 0.2s;
    }

    .action-dropdown-btn:hover {
        background: var(--ts-primary);
        color: white;
    }

    .action-dropdown-menu {
        position: absolute;
        right: 0;
        top: 100%;
        min-width: 180px;
        background: white;
        border-radius: 8px;
        box-shadow: 0 4px 20px rgba(0, 0, 0, 0.15);
        z-index: 100;
        display: none;
        padding: 0.5rem 0;
    }

    .action-dropdown-menu.show {
        display: block;
    }

    .action-dropdown-item {
        display: flex;
        align-items: center;
        gap: 0.5rem;
        padding: 0.5rem 1rem;
        color: #333;
        text-decoration: none;
        font-size: 0.85rem;
        cursor: pointer;
        transition: background 0.2s;
    }

    .action-dropdown-item:hover {
        background: #f8f9fa;
    }

    .action-dropdown-item.danger {
        color: var(--ts-danger);
    }

    .action-dropdown-item.success {
        color: var(--ts-success);
    }

    .action-dropdown-item.purple {
        color: #8b2be2;
    }

    .action-dropdown-item.info {
        color: #17a2b8;
    }

    .action-dropdown-divider {
        height: 1px;
        background: #e9ecef;
        margin: 0.5rem 0;
    }

    /* Keyword badges */
    .keyword-badge {
        transition: all 0.15s ease;
        font-size: 0.8rem;
        padding: 0.35em 0.65em;
    }

    .keyword-badge:hover {
        transform: scale(1.05);
        box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
    }

    /* SEO Strategies Panel */
    .seo-strategies-panel {
        background: linear-gradient(135deg, rgba(13, 110, 253, 0.03), rgba(13, 202, 240, 0.03));
        border: 1px solid rgba(13, 110, 253, 0.15);
        border-radius: 12px;
        padding: 1rem;
    }

    .seo-score-circle {
        width: 80px;
        height: 80px;
        border-radius: 50%;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 1.5rem;
        font-weight: 700;
        margin: 0 auto;
        position: relative;
    }

    .seo-score-circle.excellent {
        background: linear-gradient(135deg, #06d6a0, #38ef7d);
        color: white;
    }

    .seo-score-circle.good {
        background: linear-gradient(135deg, #56ab2f, #a8e063);
        color: white;
    }

    .seo-score-circle.warning {
        background: linear-gradient(135deg, #f7971e, #ffd200);
        color: #333;
    }

    .seo-score-circle.critical {
        background: linear-gradient(135deg, #ef476f, #f45c43);
        color: white;
    }

    .strategy-score-item {
        display: flex;
        align-items: center;
        gap: 0.5rem;
        padding: 0.5rem 0;
        border-bottom: 1px solid #f0f0f5;
    }

    .strategy-score-item:last-child {
        border-bottom: none;
    }

    .strategy-name {
        flex: 1;
        font-size: 0.8rem;
        color: #555;
    }

    .strategy-score {
        font-weight: 600;
        font-size: 0.85rem;
        min-width: 40px;
        text-align: right;
    }

    .strategy-score.high {
        color: #059669;
    }

    .strategy-score.medium {
        color: #d49a00;
    }

    .strategy-score.low {
        color: #ef476f;
    }

    .strategy-bar {
        width: 60px;
        height: 6px;
        background: #e9ecef;
        border-radius: 3px;
        overflow: hidden;
    }

    .strategy-bar .fill {
        height: 100%;
        border-radius: 3px;
        transition: width 0.3s;
    }

    .strategy-bar .fill.high {
        background: #06d6a0;
    }

    .strategy-bar .fill.medium {
        background: #ffd166;
    }

    .strategy-bar .fill.low {
        background: #ef476f;
    }

    .seo-suggestion-chip {
        display: inline-flex;
        align-items: center;
        gap: 0.3rem;
        padding: 0.25rem 0.5rem;
        background: rgba(13, 110, 253, 0.1);
        border-radius: 6px;
        font-size: 0.75rem;
        color: #0d6efd;
        margin: 0.2rem;
        cursor: pointer;
        transition: all 0.2s;
    }

    .seo-suggestion-chip:hover {
        background: rgba(13, 110, 253, 0.2);
    }

    .seo-suggestion-chip.applied {
        background: rgba(6, 214, 160, 0.15);
        color: #059669;
    }

    .seo-quick-actions {
        display: flex;
        gap: 0.5rem;
        margin-top: 0.75rem;
    }

    /* Compare Modal Styles */
    .compare-grid {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 1rem;
    }

    .compare-card {
        background: #f8f9fa;
        border-radius: 12px;
        padding: 1rem;
    }

    .compare-card.you {
        border: 2px solid var(--ts-primary);
    }

    .compare-card.competitor {
        border: 2px solid var(--ts-secondary);
    }

    .compare-attr-row {
        display: flex;
        justify-content: space-between;
        padding: 0.5rem 0;
        border-bottom: 1px solid #e9ecef;
    }

    .compare-attr-row:last-child {
        border-bottom: none;
    }

    .attr-value {
        font-weight: 600;
    }

    .attr-missing {
        color: var(--ts-danger);
        font-style: italic;
    }

    .attr-filled {
        color: var(--ts-success);
    }

    /* Extract Modal Styles */
    .extract-preview {
        background: #f8f9fa;
        border-radius: 12px;
        padding: 1rem;
        margin-top: 1rem;
    }

    .extract-item {
        display: flex;
        justify-content: space-between;
        align-items: center;
        padding: 0.75rem;
        background: white;
        border-radius: 8px;
        margin-bottom: 0.5rem;
        border-left: 3px solid var(--ts-success);
    }

    .extract-item.new {
        border-left-color: var(--ts-primary);
        background: rgba(94, 96, 206, 0.05);
    }

    /* Export Styles */
    .export-options {
        display: flex;
        gap: 1rem;
        margin-top: 1rem;
    }

    .export-option {
        flex: 1;
        padding: 1.5rem;
        border: 2px solid #e9ecef;
        border-radius: 12px;
        text-align: center;
        cursor: pointer;
        transition: all 0.2s;
    }

    .export-option:hover {
        border-color: var(--ts-primary);
        background: rgba(94, 96, 206, 0.05);
    }

    .export-option i {
        font-size: 2rem;
        margin-bottom: 0.5rem;
        color: var(--ts-primary);
    }

    .pagination-bar {
        display: flex;
        justify-content: space-between;
        align-items: center;
        padding: 1rem;
        border-top: 1px solid #e9ecef;
    }

    .pagination-bar .page-info {
        font-size: 0.85rem;
        color: #6c757d;
        font-weight: 500;
    }

    .pagination-bar .page-buttons {
        display: flex;
        gap: 0.5rem;
    }

    .pagination-bar .page-btn {
        padding: 0.625rem 1.25rem;
        border: 2px solid #e9ecef;
        background: white;
        border-radius: 10px;
        cursor: pointer;
        font-size: 0.85rem;
        font-weight: 600;
        transition: all 0.3s ease;
        display: inline-flex;
        align-items: center;
        gap: 0.5rem;
        position: relative;
        overflow: hidden;
    }

    .pagination-bar .page-btn::before {
        content: '';
        position: absolute;
        top: 0;
        left: -100%;
        width: 100%;
        height: 100%;
        background: linear-gradient(90deg, transparent, rgba(255, 255, 255, 0.4), transparent);
        transition: left 0.5s ease;
    }

    .pagination-bar .page-btn:hover:not(:disabled)::before {
        left: 100%;
    }

    .pagination-bar .page-btn:hover:not(:disabled) {
        background: var(--ts-gradient);
        color: white;
        border-color: transparent;
        transform: translateY(-2px);
        box-shadow: 0 4px 15px rgba(94, 96, 206, 0.35);
    }

    .pagination-bar .page-btn:hover:not(:disabled) i {
        animation: arrow-bounce 0.4s ease infinite;
    }

    @keyframes arrow-bounce {

        0%,
        100% {
            transform: translateX(0);
        }

        50% {
            transform: translateX(3px);
        }
    }

    #btn-prev:hover:not(:disabled) i {
        animation: arrow-bounce-left 0.4s ease infinite;
    }

    @keyframes arrow-bounce-left {

        0%,
        100% {
            transform: translateX(0);
        }

        50% {
            transform: translateX(-3px);
        }
    }

    .pagination-bar .page-btn:disabled {
        opacity: 0.4;
        cursor: not-allowed;
        background: #f8f9fa;
    }

    .loading-overlay {
        position: absolute;
        top: 0;
        left: 0;
        right: 0;
        bottom: 0;
        background: rgba(255, 255, 255, 0.9);
        backdrop-filter: blur(2px);
        display: none;
        align-items: center;
        justify-content: center;
        z-index: 10;
    }

    .loading-overlay.active {
        display: flex;
    }

    .spinner {
        width: 44px;
        height: 44px;
        border: 4px solid #f0f0f5;
        border-top-color: var(--ts-primary);
        border-radius: 50%;
        animation: spin 0.7s linear infinite;
    }

    @keyframes spin {
        to {
            transform: rotate(360deg);
        }
    }

    /* Quick View - Top Items Grid */
    .quick-view-section {
        background: linear-gradient(135deg, rgba(239, 71, 111, 0.03), rgba(255, 209, 102, 0.03));
        border: 1px solid rgba(239, 71, 111, 0.15);
        border-radius: 16px;
        padding: 1rem 1.25rem;
        margin-bottom: 1.5rem;
    }

    .quick-view-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 1rem;
    }

    .quick-view-header h6 {
        margin: 0;
        font-weight: 600;
        color: #ef476f;
        display: flex;
        align-items: center;
        gap: 0.5rem;
    }

    .quick-view-toggle {
        font-size: 0.8rem;
        color: #6c757d;
        cursor: pointer;
        background: none;
        border: none;
        padding: 0.25rem 0.5rem;
        border-radius: 4px;
        transition: all 0.2s;
    }

    .quick-view-toggle:hover {
        background: rgba(0, 0, 0, 0.05);
        color: #333;
    }

    .quick-view-grid {
        display: grid;
        grid-template-columns: repeat(auto-fill, minmax(220px, 1fr));
        gap: 0.75rem;
        max-height: 320px;
        overflow-y: auto;
    }

    .quick-item-card {
        background: white;
        border-radius: 10px;
        padding: 0.875rem;
        box-shadow: 0 2px 8px rgba(0, 0, 0, 0.06);
        border-left: 4px solid var(--ts-danger);
        cursor: pointer;
        transition: all 0.2s;
        position: relative;
    }

    .quick-item-card:hover {
        transform: translateY(-2px);
        box-shadow: 0 4px 16px rgba(0, 0, 0, 0.1);
    }

    .quick-item-card.medium {
        border-left-color: var(--ts-warning);
    }

    .quick-item-card.low {
        border-left-color: var(--ts-secondary);
    }

    .quick-item-title {
        font-size: 0.8rem;
        font-weight: 500;
        color: #333;
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
        margin-bottom: 0.5rem;
    }

    .quick-item-id {
        font-size: 0.7rem;
        color: #999;
        margin-bottom: 0.5rem;
    }

    .quick-item-gaps {
        display: flex;
        align-items: center;
        justify-content: space-between;
    }

    .quick-total-gaps {
        font-size: 1.5rem;
        font-weight: 700;
        color: var(--ts-danger);
        line-height: 1;
    }

    .quick-total-gaps.medium {
        color: var(--ts-warning);
    }

    .quick-total-gaps.low {
        color: var(--ts-secondary);
    }

    .quick-gaps-breakdown {
        display: flex;
        gap: 0.5rem;
        font-size: 0.7rem;
    }

    .quick-gap-pill {
        display: inline-flex;
        align-items: center;
        gap: 0.2rem;
        padding: 0.15rem 0.4rem;
        border-radius: 8px;
        font-weight: 500;
    }

    .quick-gap-pill.critical {
        background: rgba(239, 71, 111, 0.15);
        color: var(--ts-danger);
    }

    .quick-gap-pill.filter {
        background: rgba(255, 209, 102, 0.2);
        color: #d49a00;
    }

    .quick-gap-pill.hidden {
        background: rgba(138, 43, 226, 0.1);
        color: #8b2be2;
    }

    .quick-view-empty {
        text-align: center;
        padding: 2rem;
        color: #6c757d;
    }

    .quick-view-loading {
        text-align: center;
        padding: 1.5rem;
    }

    /* Enhanced Table - Total Column */
    .total-gaps-cell {
        text-align: center;
    }

    .total-gaps-badge {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        min-width: 36px;
        height: 36px;
        border-radius: 8px;
        font-weight: 700;
        font-size: 1rem;
    }

    .total-gaps-badge.critical {
        background: rgba(239, 71, 111, 0.15);
        color: var(--ts-danger);
    }

    .total-gaps-badge.warning {
        background: rgba(255, 209, 102, 0.2);
        color: #d49a00;
    }

    .total-gaps-badge.good {
        background: rgba(6, 214, 160, 0.15);
        color: #059669;
    }

    .empty-state {
        text-align: center;
        padding: 3rem;
        color: #6c757d;
    }

    .empty-state i {
        font-size: 3rem;
        margin-bottom: 1rem;
        opacity: 0.3;
    }

    /* Job Result Modal */
    .modal-job-result .progress {
        height: 24px;
        border-radius: 12px;
    }

    .modal-job-result .progress-bar {
        border-radius: 12px;
        font-weight: 600;
    }
</style>

<div class="container-fluid py-4">
    <!-- Header -->
    <div class="ts-header">
        <h1>🧾 Ficha Técnica</h1>
        <p class="mb-0 opacity-75">Gerencie atributos e complete seus anúncios para melhor ranking</p>
    </div>

    <!-- KPIs -->
    <div class="kpi-grid" id="kpi-grid">
        <div class="kpi-card">
            <div class="value" id="kpi-total"><?= htmlspecialchars($tsSsrStats['total_items'], ENT_QUOTES, 'UTF-8') ?></div>
            <div class="label">Total de Anúncios</div>
        </div>
        <div class="kpi-card danger">
            <div class="value" id="kpi-critical"><?= htmlspecialchars($tsSsrStats['critical_gap_items'], ENT_QUOTES, 'UTF-8') ?></div>
            <div class="label">Lacunas Críticas</div>
        </div>
        <div class="kpi-card hidden" title="Atributos ocultos melhoram o ranking de busca">
            <div class="value" id="kpi-hidden"><?= htmlspecialchars($tsSsrStats['hidden_gap_items'], ENT_QUOTES, 'UTF-8') ?></div>
            <div class="label">🔮 Hidden SEO</div>
        </div>
        <div class="kpi-card purple" title="Cobertura de sugestões de MODELO para motor de busca">
            <div class="value" id="kpi-model"><?= htmlspecialchars($tsSsrStats['model_label'], ENT_QUOTES, 'UTF-8') ?></div>
            <div class="label">🔍 Modelos</div>
        </div>
        <div class="kpi-card warning">
            <div class="value" id="kpi-pending"><?= htmlspecialchars($tsSsrStats['pending_suggestions_total'], ENT_QUOTES, 'UTF-8') ?></div>
            <div class="label">Sugestões Pendentes</div>
        </div>
        <div class="kpi-card success">
            <div class="value" id="kpi-completeness"><?= htmlspecialchars($tsSsrStats['avg_completeness_label'], ENT_QUOTES, 'UTF-8') ?></div>
            <div class="label">Completude Média</div>
        </div>
    </div>

    <div id="tech-sheet-hidden-banner" class="alert alert-info border mt-3 mb-0 py-2 px-3 small d-none" role="status"></div>

    <!-- 🎯 Smart Fill Widget -->
    <div class="smart-fill-widget mb-3" id="smart-fill-widget" style="display: none;">
        <div class="card border-0 shadow-sm" style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white;">
            <div class="card-body py-3">
                <div class="row align-items-center">
                    <div class="col-auto">
                        <div class="smart-fill-icon" style="font-size: 2.5rem; opacity: 0.9;">🎯</div>
                    </div>
                    <div class="col">
                        <h6 class="mb-1 fw-bold">Smart Fill SEO</h6>
                        <div class="d-flex gap-4 small">
                            <span><i class="fas fa-hourglass-half"></i> <span id="sf-pending">0</span> pendentes</span>
                            <span><i class="fas fa-check-circle"></i> <span id="sf-applied">0</span> aplicadas</span>
                            <span><i class="fas fa-chart-line"></i> <span id="sf-confidence">0</span>% confiança</span>
                            <span><i class="fas fa-bullseye"></i> <span id="sf-coverage">0</span>% cobertura</span>
                        </div>
                    </div>
                    <div class="col-auto">
                        <span id="sf-action" class="badge bg-white text-dark px-3 py-2" style="font-size: 0.8rem;"></span>
                    </div>
                    <div class="col-auto">
                        <div class="dropdown">
                            <button class="btn btn-light btn-sm dropdown-toggle" type="button" data-bs-toggle="dropdown">
                                <i class="fas fa-cog"></i>
                            </button>
                            <ul class="dropdown-menu dropdown-menu-end">
                                <li><a class="dropdown-item" href="#" onclick="TechSheet.openSmartFillDashboard()"><i class="fas fa-chart-pie me-2"></i>Dashboard Completo</a></li>
                                <li><a class="dropdown-item" href="#" onclick="TechSheet.autoApproveSmartFill()"><i class="fas fa-magic me-2"></i>Auto-Aprovar Alta Confiança</a></li>
                                <li>
                                    <hr class="dropdown-divider">
                                </li>
                                <li><a class="dropdown-item" href="#" onclick="TechSheet.bulkSmartFill()"><i class="fas fa-bolt me-2"></i>Executar Smart Fill em Lote</a></li>
                            </ul>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Quick View - Itens Mais Urgentes -->
    <div class="quick-view-section" id="quick-view-section">
        <div class="quick-view-header">
            <h6>🚨 Itens com Mais Atributos Faltantes</h6>
            <div>
                <span class="text-muted small me-2" id="quick-view-summary">Carregando...</span>
                <button class="quick-view-toggle" onclick="TechSheet.toggleQuickView()" id="quick-view-toggle">
                    <i class="fas fa-chevron-up"></i> Ocultar
                </button>
            </div>
        </div>
        <div id="quick-view-content">
            <div class="quick-view-loading">
                <div class="spinner-border spinner-border-sm text-primary"></div>
                <span class="ms-2 text-muted">Carregando itens urgentes...</span>
            </div>
        </div>
    </div>

    <!-- Tabs -->
    <div class="tab-pills">
        <button class="tab-pill active" data-tab="pending" onclick="TechSheet.setTab('pending')">
            ⚠️ Pendentes <span class="badge" id="tab-pending-count">0</span>
        </button>
        <button class="tab-pill" data-tab="hidden" onclick="TechSheet.setTab('hidden')" title="Itens com atributos ocultos de SEO faltando">
            🔮 Hidden SEO <span class="badge" id="tab-hidden-count">0</span>
        </button>
        <button class="tab-pill" data-tab="review" onclick="TechSheet.setTab('review')">
            📋 Em Revisão <span class="badge" id="tab-review-count">0</span>
        </button>
        <button class="tab-pill" data-tab="done" onclick="TechSheet.setTab('done')">
            ✅ Concluídos <span class="badge" id="tab-done-count">0</span>
        </button>
        <button class="tab-pill" data-tab="all" onclick="TechSheet.setTab('all')">
            📦 Todos
        </button>
    </div>

    <!-- Filters -->
    <div class="filters-bar">
        <input type="text" class="form-control" id="filter-search" placeholder="🔍 Buscar por título ou ID..." onkeyup="TechSheet.debounceSearch(event)">
        <select class="form-select" id="filter-category" onchange="TechSheet.loadList()">
            <option value="">Todas categorias</option>
        </select>
        <select class="form-select" id="filter-sort" onchange="TechSheet.loadList()">
            <option value="missing_required">🔥 Mais faltantes (críticos)</option>
            <option value="total_gaps">📊 Total de faltantes</option>
            <option value="completeness">📉 Menor completude</option>
            <option value="missing_hidden">🔮 Mais Hidden SEO</option>
            <option value="pending_suggestions">💡 Mais sugestões</option>
            <option value="">🕐 Mais recente</option>
        </select>
        <button class="btn btn-sm btn-outline-primary" onclick="TechSheet.syncItems()">
            <i class="fas fa-sync-alt"></i> Sincronizar
        </button>
        <button class="btn btn-sm btn-outline-secondary" onclick="TechSheet.loadList()">
            <i class="fas fa-redo"></i> Atualizar
        </button>
        <button class="btn btn-sm btn-outline-success" onclick="TechSheet.openExportModal()" title="Exportar relatório">
            <i class="fas fa-file-export"></i> Exportar
        </button>
        <button class="btn btn-sm btn-outline-info" onclick="TechSheet.openMotoKeywordsModal()" title="Ver keywords mineradas de categorias de motos">
            <i class="fas fa-gem"></i> Keywords Moto
        </button>
        <button class="btn btn-sm btn-outline-purple" onclick="TechSheet.openMarketAnalysis()" title="Análise de mercado via API real">
            <i class="fas fa-chart-line"></i> Mercado
        </button>
    </div>

    <!-- Table -->
    <div class="items-table-container position-relative">
        <div class="loading-overlay" id="table-loading">
            <div class="spinner"></div>
        </div>

        <table class="items-table" id="items-table">
            <thead>
                <tr>
                    <th style="width: 40px;">
                        <input type="checkbox" class="form-check-input" id="select-all" onchange="TechSheet.toggleSelectAll(this.checked)">
                    </th>
                    <th>Anúncio</th>
                    <th style="width: 80px; text-align: center;" title="Total de atributos faltantes">Total ⚠️</th>
                    <th style="width: 150px;">Completude</th>
                    <th style="width: 180px;">Detalhes Lacunas</th>
                    <th style="width: 120px;">Sugestões</th>
                    <th style="width: 140px;">Ações</th>
                </tr>
            </thead>
            <tbody id="items-tbody">
                <!-- Dynamic content -->
            </tbody>
        </table>

        <div class="empty-state" id="empty-state" style="display: none;">
            <i class="fas fa-inbox"></i>
            <h5>Nenhum item encontrado</h5>
            <p>Tente ajustar os filtros ou sincronize seus anúncios</p>
        </div>

        <div class="pagination-bar" id="pagination-bar">
            <div class="page-info">
                Mostrando <span id="page-showing">0</span> de <span id="page-total">0</span> itens
            </div>
            <div class="page-buttons">
                <button class="page-btn" id="btn-prev" onclick="TechSheet.prevPage()" disabled>
                    <i class="fas fa-chevron-left"></i> Anterior
                </button>
                <button class="page-btn" id="btn-next" onclick="TechSheet.nextPage()" disabled>
                    Próximo <i class="fas fa-chevron-right"></i>
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Bulk Action Bar -->
<div class="bulk-bar" id="bulk-bar">
    <div class="selected-info">
        <span id="selected-count">0</span> itens selecionados
    </div>
    <div class="bulk-actions">
        <button class="btn btn-outline-info btn-sm" onclick="TechSheet.bulkRefresh()" title="Buscar dados atualizados da API do ML">
            <i class="fas fa-sync-alt"></i> Atualizar da API
        </button>
        <button class="btn btn-outline-success btn-sm" onclick="TechSheet.bulkQuickSuggestions()" title="Gerar sugestões rápidas por extração do título (sem IA)">
            <i class="fas fa-bolt"></i> Sugestões Rápidas
        </button>
        <button class="btn btn-gradient-purple btn-sm" onclick="TechSheet.bulkSmartFill()" title="🎯 Smart Fill: múltiplas fontes SEO (título, descrição, benchmark, autocomplete, trends)">
            <i class="fas fa-brain"></i> 🎯 Smart Fill SEO
        </button>
        <button class="btn btn-outline-purple btn-sm" onclick="TechSheet.bulkModelSuggestions()" title="Gerar sugestões de MODELO usando estratégias de busca avançadas">
            <i class="fas fa-search-plus"></i> Sugestões Modelo
        </button>
        <!-- 🚀 BULK SEO - Otimização de Título e Descrição em Lote -->
        <button class="btn btn-warning btn-sm" onclick="BulkSEO.openModal()" title="🚀 Bulk SEO: Otimização de Título e Descrição com preview e aplicação segura">
            <i class="fas fa-rocket"></i> 🚀 Bulk SEO
        </button>
        <select class="form-select form-select-sm" id="bulk-confidence" style="width: 120px;">
            <option value="85">≥ 85%</option>
            <option value="90">≥ 90%</option>
            <option value="95">≥ 95%</option>
            <option value="70">≥ 70%</option>
        </select>
        <button class="btn btn-outline-warning btn-sm" onclick="TechSheet.bulkApprove()">
            <i class="fas fa-check"></i> Aprovar Pendentes
        </button>
        <button class="btn btn-outline-primary btn-sm" onclick="TechSheet.bulkGenerate()">
            <i class="fas fa-magic"></i> Gerar Sugestões (IA)
        </button>
        <button class="btn btn-success btn-sm" onclick="TechSheet.bulkApply()">
            <i class="fas fa-upload"></i> Aplicar Aprovadas
        </button>
        <button class="btn btn-outline-secondary btn-sm" onclick="TechSheet.clearSelection()">
            Limpar
        </button>
    </div>
</div>

<!-- Item Drawer -->
<div class="drawer-backdrop" id="drawer-backdrop" onclick="TechSheet.closeDrawer()"></div>
<div class="item-drawer" id="item-drawer">
    <div class="drawer-header">
        <h5 id="drawer-title">Detalhes do Item</h5>
        <button class="btn-close" onclick="TechSheet.closeDrawer()"></button>
    </div>
    <div class="drawer-body" id="drawer-body">
        <!-- Dynamic content -->
    </div>
    <div class="drawer-footer">
        <button class="btn btn-outline-secondary" onclick="TechSheet.closeDrawer()">Fechar</button>
        <button class="btn btn-outline-primary" id="drawer-btn-generate" onclick="TechSheet.drawerGenerate()">
            <i class="fas fa-magic"></i> Gerar Sugestões
        </button>
        <button class="btn btn-success" id="drawer-btn-apply" onclick="TechSheet.drawerApply()">
            <i class="fas fa-check"></i> Aplicar Aprovadas
        </button>
    </div>
</div>

<!-- Job Result Modal -->
<div class="modal fade modal-job-result" id="jobResultModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">🚀 Processamento em Andamento</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="text-center mb-3">
                    <div class="spinner-border text-primary" id="job-spinner"></div>
                </div>
                <div class="progress mb-3">
                    <div class="progress-bar progress-bar-striped progress-bar-animated" id="job-progress" style="width: 0%">0%</div>
                </div>
                <div id="job-status-text" class="text-center text-muted">Iniciando...</div>
                <div id="job-result-summary" class="mt-3" style="display: none;"></div>
            </div>
            <div class="modal-footer" id="job-modal-footer" style="display: none;">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Fechar</button>
                <button type="button" class="btn btn-primary" onclick="TechSheet.loadList(); bootstrap.Modal.getInstance(document.getElementById('jobResultModal')).hide();">
                    Atualizar Lista
                </button>
            </div>
        </div>
    </div>
</div>

<!-- 🚀 BULK SEO Modal - Otimização em Lote de Título e Descrição -->
<div class="modal fade" id="bulkSeoModal" tabindex="-1" data-bs-backdrop="static">
    <div class="modal-dialog modal-xl modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header" style="background: linear-gradient(135deg, #f59e0b, #ef4444); color: white;">
                <h5 class="modal-title"><i class="fas fa-rocket me-2"></i>🚀 Bulk SEO - Otimização em Lote</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <!-- Step 1: Options -->
                <div id="bulk-seo-step-options" class="bulk-seo-step">
                    <div class="alert alert-info">
                        <i class="fas fa-info-circle me-2"></i>
                        <strong>Fluxo Seguro:</strong> Dry-run → Revisar Diferenças → Aprovar → Aplicar
                    </div>

                    <div class="row mb-4">
                        <div class="col-md-6">
                            <div class="form-check form-switch mb-3">
                                <input class="form-check-input" type="checkbox" id="bulk-seo-optimize-title" checked>
                                <label class="form-check-label fw-bold" for="bulk-seo-optimize-title">
                                    📝 Otimizar Títulos
                                </label>
                                <div class="small text-muted">Gera títulos SEO otimizados (máx. 60 caracteres)</div>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="form-check form-switch mb-3">
                                <input class="form-check-input" type="checkbox" id="bulk-seo-optimize-description" checked>
                                <label class="form-check-label fw-bold" for="bulk-seo-optimize-description">
                                    📄 Otimizar Descrições
                                </label>
                                <div class="small text-muted">Gera descrições com keywords e estrutura SEO</div>
                            </div>
                        </div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-bold">Itens Selecionados (<span id="bulk-seo-count">0</span>)</label>
                        <div id="bulk-seo-items-preview" class="border rounded p-2" style="max-height: 150px; overflow-y: auto;">
                            <p class="text-muted mb-0">Nenhum item selecionado</p>
                        </div>
                    </div>

                    <button class="btn btn-warning btn-lg w-100" onclick="BulkSEO.runDryRun()">
                        <i class="fas fa-search me-2"></i> Executar Dry-Run (Preview)
                    </button>
                </div>

                <!-- Step 2: Loading -->
                <div id="bulk-seo-step-loading" class="bulk-seo-step text-center py-5" style="display: none;">
                    <div class="spinner-border text-warning mb-3" style="width: 3rem; height: 3rem;"></div>
                    <h5>Analisando itens...</h5>
                    <p class="text-muted" id="bulk-seo-loading-status">Gerando sugestões de otimização...</p>
                    <div class="progress mt-3" style="height: 8px;">
                        <div class="progress-bar bg-warning" id="bulk-seo-loading-progress" style="width: 0%"></div>
                    </div>
                </div>

                <!-- Step 3: Review -->
                <div id="bulk-seo-step-review" class="bulk-seo-step" style="display: none;">
                    <!-- Stats -->
                    <div class="row mb-4">
                        <div class="col-md-3">
                            <div class="kpi-card success">
                                <div class="value" id="bulk-seo-stat-changes">0</div>
                                <div class="label">Com Mudanças</div>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="kpi-card">
                                <div class="value" id="bulk-seo-stat-noop">0</div>
                                <div class="label">Sem Mudança (No-op)</div>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="kpi-card warning">
                                <div class="value" id="bulk-seo-stat-risk">0</div>
                                <div class="label">Com Risco</div>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="kpi-card danger">
                                <div class="value" id="bulk-seo-stat-errors">0</div>
                                <div class="label">Erros</div>
                            </div>
                        </div>
                    </div>

                    <!-- Bulk Actions -->
                    <div class="d-flex gap-2 mb-3">
                        <button class="btn btn-outline-success btn-sm" onclick="BulkSEO.selectAllChanges()">
                            <i class="fas fa-check-double"></i> Selecionar Todos com Mudança
                        </button>
                        <button class="btn btn-outline-secondary btn-sm" onclick="BulkSEO.deselectAll()">
                            <i class="fas fa-times"></i> Desmarcar Todos
                        </button>
                        <div class="ms-auto">
                            <span class="badge bg-info" id="bulk-seo-selected-for-apply">0 selecionados para aplicar</span>
                        </div>
                    </div>

                    <!-- Items List -->
                    <div id="bulk-seo-items-list" style="max-height: 400px; overflow-y: auto;">
                        <!-- Dynamic content -->
                    </div>
                </div>

                <!-- Step 4: Applying -->
                <div id="bulk-seo-step-applying" class="bulk-seo-step text-center py-5" style="display: none;">
                    <div class="spinner-border text-success mb-3" style="width: 3rem; height: 3rem;"></div>
                    <h5>Aplicando otimizações...</h5>
                    <p class="text-muted" id="bulk-seo-applying-status">Processando...</p>
                    <div class="progress mt-3" style="height: 8px;">
                        <div class="progress-bar bg-success" id="bulk-seo-applying-progress" style="width: 0%"></div>
                    </div>
                </div>

                <!-- Step 5: Results -->
                <div id="bulk-seo-step-results" class="bulk-seo-step" style="display: none;">
                    <div class="alert alert-success mb-4" id="bulk-seo-result-alert">
                        <i class="fas fa-check-circle me-2"></i>
                        <strong>Otimização concluída!</strong>
                    </div>

                    <div class="row mb-4">
                        <div class="col-md-3">
                            <div class="kpi-card success">
                                <div class="value" id="bulk-seo-result-titles">0</div>
                                <div class="label">Títulos Aplicados</div>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="kpi-card success">
                                <div class="value" id="bulk-seo-result-descs">0</div>
                                <div class="label">Descrições Aplicadas</div>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="kpi-card danger">
                                <div class="value" id="bulk-seo-result-errors">0</div>
                                <div class="label">Falhas</div>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="kpi-card purple">
                                <div class="value" id="bulk-seo-result-versions">0</div>
                                <div class="label">Versões Criadas</div>
                            </div>
                        </div>
                    </div>

                    <div id="bulk-seo-result-details" style="max-height: 300px; overflow-y: auto;">
                        <!-- Dynamic content -->
                    </div>

                    <!-- Rollback Section -->
                    <div class="alert alert-warning mt-3" id="bulk-seo-rollback-section" style="display: none;">
                        <div class="d-flex align-items-center justify-content-between">
                            <div>
                                <i class="fas fa-undo me-2"></i>
                                <strong>Rollback disponível:</strong>
                                <span id="bulk-seo-rollback-count">0</span> versões podem ser revertidas.
                            </div>
                            <button class="btn btn-sm btn-outline-danger" onclick="BulkSEO.showRollbackConfirm()">
                                <i class="fas fa-undo me-1"></i> Reverter Todas
                            </button>
                        </div>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal" id="bulk-seo-btn-close">Fechar</button>
                <button type="button" class="btn btn-outline-primary" onclick="BulkSEO.backToOptions()" id="bulk-seo-btn-back" style="display: none;">
                    <i class="fas fa-arrow-left me-1"></i> Voltar
                </button>
                <button type="button" class="btn btn-success" onclick="BulkSEO.applySelected()" id="bulk-seo-btn-apply" style="display: none;">
                    <i class="fas fa-check me-1"></i> Aplicar Selecionados
                </button>
            </div>
        </div>
    </div>
</div>

<style>
    /* Bulk SEO Modal Styles */
    .bulk-seo-item {
        background: #f8f9fa;
        border-radius: 10px;
        padding: 1rem;
        margin-bottom: 0.75rem;
        border-left: 4px solid #dee2e6;
    }

    .bulk-seo-item.has-changes {
        border-left-color: #28a745;
    }

    .bulk-seo-item.no-op {
        border-left-color: #6c757d;
        opacity: 0.7;
    }

    .bulk-seo-item.has-risk {
        border-left-color: #ffc107;
    }

    .bulk-seo-item.has-error {
        border-left-color: #dc3545;
    }

    .bulk-seo-item .item-header {
        display: flex;
        align-items: center;
        gap: 0.75rem;
        margin-bottom: 0.75rem;
    }

    .bulk-seo-item .item-title {
        font-weight: 600;
        flex: 1;
        overflow: hidden;
        text-overflow: ellipsis;
        white-space: nowrap;
    }

    .bulk-seo-diff {
        background: white;
        border-radius: 6px;
        padding: 0.75rem;
        margin-top: 0.5rem;
        font-size: 0.85rem;
    }

    .bulk-seo-diff .diff-before {
        background: #fee2e2;
        padding: 0.5rem;
        border-radius: 4px;
        margin-bottom: 0.5rem;
    }

    .bulk-seo-diff .diff-after {
        background: #d1fae5;
        padding: 0.5rem;
        border-radius: 4px;
    }

    .bulk-seo-diff del {
        background: #fca5a5;
        text-decoration: line-through;
    }

    .bulk-seo-diff ins {
        background: #86efac;
        text-decoration: none;
    }

    .risk-badge {
        padding: 0.2rem 0.5rem;
        border-radius: 4px;
        font-size: 0.7rem;
        font-weight: 600;
    }

    .risk-badge.low {
        background: #e0f2fe;
        color: #0369a1;
    }

    .risk-badge.medium {
        background: #fef3c7;
        color: #92400e;
    }

    .risk-badge.high {
        background: #fee2e2;
        color: #dc2626;
    }

    /* Bulk SEO - Editable Fields */
    .bulk-seo-editable {
        border: 1px solid #dee2e6;
        border-radius: 4px;
        padding: 0.5rem;
        font-size: 0.85rem;
        width: 100%;
        min-height: 60px;
        resize: vertical;
        transition: border-color 0.2s;
    }

    .bulk-seo-editable:focus {
        border-color: #5e60ce;
        outline: none;
        box-shadow: 0 0 0 2px rgba(94, 96, 206, 0.15);
    }

    .bulk-seo-editable.title-field {
        min-height: 36px;
        resize: none;
    }

    .bulk-seo-char-count {
        font-size: 0.75rem;
        color: #6c757d;
        text-align: right;
        margin-top: 2px;
    }

    .bulk-seo-char-count.warn {
        color: #f59e0b;
    }

    .bulk-seo-char-count.danger {
        color: #dc2626;
    }

    /* Bulk SEO - Field Toggle */
    .bulk-seo-field-toggle {
        display: flex;
        align-items: center;
        gap: 0.5rem;
        margin-bottom: 0.5rem;
    }

    .bulk-seo-field-toggle .form-check-input {
        margin: 0;
    }

    /* Bulk SEO - Async Progress */
    .bulk-seo-async-progress {
        margin: 1rem 0;
    }

    .bulk-seo-progress-text {
        display: flex;
        justify-content: space-between;
        font-size: 0.85rem;
        margin-bottom: 0.5rem;
    }

    /* Bulk SEO - Rollback */
    .bulk-seo-rollback-btn {
        font-size: 0.75rem;
        padding: 0.2rem 0.5rem;
    }

    .bulk-seo-version-link {
        font-size: 0.75rem;
        color: #6c757d;
        text-decoration: none;
    }

    .bulk-seo-version-link:hover {
        color: #5e60ce;
    }
</style>

<!-- Extract from Title Modal -->
<div class="modal fade" id="extractModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header" style="background: var(--ts-gradient); color: white;">
                <h5 class="modal-title"><i class="fas fa-magic me-2"></i>Extrair Atributos do Título</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="mb-3">
                    <label class="form-label fw-bold">Título do Anúncio</label>
                    <input type="text" class="form-control form-control-lg" id="extract-title" readonly>
                </div>

                <div id="extract-loading" class="text-center py-4" style="display: none;">
                    <div class="spinner-border text-primary"></div>
                    <p class="mt-2 text-muted">Analisando título com IA...</p>
                </div>

                <div id="extract-results" style="display: none;">
                    <h6 class="fw-bold mb-3">📋 Atributos Detectados</h6>
                    <div id="extract-list" class="extract-preview"></div>

                    <div class="alert alert-info mt-3">
                        <i class="fas fa-info-circle me-2"></i>
                        Os atributos marcados serão adicionados como sugestões pendentes de aprovação.
                    </div>
                </div>

                <div id="extract-empty" class="text-center py-4 text-muted" style="display: none;">
                    <i class="fas fa-search fa-3x mb-3 opacity-50"></i>
                    <p>Nenhum atributo detectado no título.</p>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                <button type="button" class="btn btn-success" id="btn-apply-extract" onclick="TechSheet.applyExtracted()" disabled>
                    <i class="fas fa-check me-1"></i> Adicionar como Sugestões
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Compare with Competitors Modal -->
<div class="modal fade" id="compareModal" tabindex="-1">
    <div class="modal-dialog modal-xl">
        <div class="modal-content">
            <div class="modal-header" style="background: linear-gradient(135deg, #48bfe3, #5e60ce); color: white;">
                <h5 class="modal-title"><i class="fas fa-balance-scale me-2"></i>Comparar com Concorrentes</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div id="compare-loading" class="text-center py-5">
                    <div class="spinner-border text-primary"></div>
                    <p class="mt-2 text-muted">Analisando concorrentes da categoria...</p>
                </div>

                <div id="compare-results" style="display: none;">
                    <div class="alert alert-info mb-4">
                        <i class="fas fa-lightbulb me-2"></i>
                        Comparação baseada nos <strong>top vendedores</strong> da mesma categoria.
                    </div>

                    <div class="row mb-4">
                        <div class="col-md-4">
                            <div class="kpi-card success">
                                <div class="value" id="compare-your-score">-</div>
                                <div class="label">Concorrentes Analisados</div>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="kpi-card warning">
                                <div class="value" id="compare-avg-score">-</div>
                                <div class="label">Atributos Faltantes</div>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="kpi-card" id="compare-position-card">
                                <div class="value" id="compare-position">-</div>
                                <div class="label">Status</div>
                            </div>
                        </div>
                    </div>

                    <h6 class="fw-bold mb-3">📊 Atributos que Concorrentes Têm e Você Não</h6>
                    <div id="compare-missing-list" style="max-height: 250px; overflow-y: auto;"></div>

                    <h6 class="fw-bold mb-3 mt-4">🏆 Top Concorrentes Analisados</h6>
                    <div id="compare-common-list" style="max-height: 200px; overflow-y: auto;"></div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Fechar</button>
                <button type="button" class="btn btn-primary" id="btn-fill-from-competitors" onclick="TechSheet.fillFromCompetitors()" style="display: none;">
                    <i class="fas fa-copy me-1"></i> Copiar Atributos Faltantes
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Export Report Modal -->
<div class="modal fade" id="exportModal" tabindex="-1">

    <!-- Mined Keywords Modal -->
    <div class="modal fade" id="keywordsModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header" style="background: linear-gradient(135deg, #17a2b8, #20c997); color: white;">
                    <h5 class="modal-title"><i class="fas fa-gem me-2"></i>Keywords Mineradas da Categoria</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div id="keywords-loading" class="text-center py-5">
                        <div class="spinner-border text-info"></div>
                        <p class="mt-2 text-muted">Minerando keywords da API do Mercado Livre...</p>
                    </div>

                    <div id="keywords-results" style="display: none;">
                        <div class="row mb-3">
                            <div class="col-md-4">
                                <div class="kpi-card" style="border-left: 4px solid #17a2b8;">
                                    <div class="value text-info" id="kw-total">0</div>
                                    <div class="label">Keywords Totais</div>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="kpi-card" style="border-left: 4px solid #20c997;">
                                    <div class="value text-success" id="kw-values">0</div>
                                    <div class="label">Valores de Atributos</div>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="kpi-card" style="border-left: 4px solid #6c757d;">
                                    <div class="value text-secondary" id="kw-names">0</div>
                                    <div class="label">Nomes de Atributos</div>
                                </div>
                            </div>
                        </div>

                        <div class="mb-3">
                            <input type="text" class="form-control" id="kw-filter" placeholder="🔍 Filtrar keywords..." oninput="TechSheet.filterKeywords(this.value)">
                        </div>

                        <h6 class="fw-bold mb-2"><i class="fas fa-tags text-info me-2"></i>Keywords por Relevância</h6>
                        <div id="keywords-list" style="max-height: 350px; overflow-y: auto;"></div>

                        <div class="alert alert-light mt-3 small">
                            <i class="fas fa-info-circle text-info me-1"></i>
                            <strong>Dica:</strong> Use estas keywords para otimizar títulos, descrições e preencher atributos faltantes.
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-info" onclick="TechSheet.copyKeywords()">
                        <i class="fas fa-copy me-1"></i> Copiar Todas
                    </button>
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Fechar</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Market Analysis Modal -->
    <div class="modal fade" id="marketAnalysisModal" tabindex="-1">
        <div class="modal-dialog modal-xl">
            <div class="modal-content">
                <div class="modal-header" style="background: linear-gradient(135deg, #6366f1, #8b5cf6); color: white;">
                    <h5 class="modal-title"><i class="fas fa-chart-line me-2"></i>Análise de Mercado (API Real)</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div id="market-loading" class="text-center py-5">
                        <div class="spinner-border text-primary"></div>
                        <p class="mt-2 text-muted">Analisando mercado via API do Mercado Livre...</p>
                    </div>

                    <div id="market-results" style="display: none;">
                        <!-- Category Info -->
                        <div class="alert alert-light mb-4">
                            <div class="d-flex align-items-center">
                                <i class="fas fa-folder-open text-primary me-3 fa-2x"></i>
                                <div>
                                    <strong id="market-category-name">-</strong>
                                    <small class="d-block text-muted" id="market-category-path">-</small>
                                </div>
                                <div class="ms-auto text-end">
                                    <div class="fs-5 fw-bold text-primary" id="market-total-items">-</div>
                                    <small class="text-muted">itens na categoria</small>
                                </div>
                            </div>
                        </div>

                        <!-- Price Analysis -->
                        <h6 class="fw-bold mb-3"><i class="fas fa-dollar-sign text-success me-2"></i>Análise de Preços</h6>
                        <div class="row mb-4">
                            <div class="col-md-3">
                                <div class="kpi-card" style="border-left: 4px solid #22c55e;">
                                    <div class="value text-success" id="market-price-min">-</div>
                                    <div class="label">Preço Mínimo</div>
                                </div>
                            </div>
                            <div class="col-md-3">
                                <div class="kpi-card" style="border-left: 4px solid #6366f1;">
                                    <div class="value text-primary" id="market-price-median">-</div>
                                    <div class="label">Mediana</div>
                                </div>
                            </div>
                            <div class="col-md-3">
                                <div class="kpi-card" style="border-left: 4px solid #f59e0b;">
                                    <div class="value text-warning" id="market-price-avg">-</div>
                                    <div class="label">Preço Médio</div>
                                </div>
                            </div>
                            <div class="col-md-3">
                                <div class="kpi-card" style="border-left: 4px solid #ef4444;">
                                    <div class="value text-danger" id="market-price-max">-</div>
                                    <div class="label">Preço Máximo</div>
                                </div>
                            </div>
                        </div>

                        <!-- Market Features -->
                        <h6 class="fw-bold mb-3"><i class="fas fa-chart-pie text-info me-2"></i>Características do Mercado</h6>
                        <div class="row mb-4">
                            <div class="col-md-3">
                                <div class="text-center p-3 bg-light rounded">
                                    <div class="fs-4 fw-bold text-success" id="market-free-shipping">-%</div>
                                    <small class="text-muted">Frete Grátis</small>
                                </div>
                            </div>
                            <div class="col-md-3">
                                <div class="text-center p-3 bg-light rounded">
                                    <div class="fs-4 fw-bold text-primary" id="market-full">-%</div>
                                    <small class="text-muted">Full/Fulfillment</small>
                                </div>
                            </div>
                            <div class="col-md-3">
                                <div class="text-center p-3 bg-light rounded">
                                    <div class="fs-4 fw-bold text-warning" id="market-official">-%</div>
                                    <small class="text-muted">Lojas Oficiais</small>
                                </div>
                            </div>
                            <div class="col-md-3">
                                <div class="text-center p-3 bg-light rounded">
                                    <div class="fs-4 fw-bold text-info" id="market-catalog">-%</div>
                                    <small class="text-muted">No Catálogo</small>
                                </div>
                            </div>
                        </div>

                        <!-- Recommendations -->
                        <h6 class="fw-bold mb-3"><i class="fas fa-lightbulb text-warning me-2"></i>Recomendações</h6>
                        <div id="market-recommendations" class="mb-4"></div>

                        <!-- Related Domains -->
                        <h6 class="fw-bold mb-3"><i class="fas fa-sitemap text-purple me-2"></i>Categorias Relacionadas</h6>
                        <div id="market-domains" class="mb-3"></div>

                        <div class="alert alert-info small">
                            <i class="fas fa-info-circle me-1"></i>
                            <strong>Fonte:</strong> <span id="market-source">-</span> |
                            <strong>Amostra:</strong> <span id="market-sample">-</span> itens
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-primary" onclick="TechSheet.refreshMarketAnalysis()">
                        <i class="fas fa-sync me-1"></i> Atualizar
                    </button>
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Fechar</button>
                </div>
            </div>
        </div>
    </div>

    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fas fa-file-export me-2"></i>Exportar Relatório</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <p class="text-muted">Escolha o formato do relatório de completude:</p>

                <div class="export-options">
                    <div class="export-option" onclick="TechSheet.exportReport('csv')">
                        <i class="fas fa-file-csv"></i>
                        <div class="fw-bold">CSV</div>
                        <small class="text-muted">Excel, Google Sheets</small>
                    </div>
                    <div class="export-option" onclick="TechSheet.exportReport('json')">
                        <i class="fas fa-file-code"></i>
                        <div class="fw-bold">JSON</div>
                        <small class="text-muted">Desenvolvedores</small>
                    </div>
                </div>

                <div class="mt-4">
                    <label class="form-label fw-bold">Incluir no relatório:</label>
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" id="export-include-gaps" checked>
                        <label class="form-check-label" for="export-include-gaps">Lacunas detalhadas</label>
                    </div>
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" id="export-include-suggestions" checked>
                        <label class="form-check-label" for="export-include-suggestions">Sugestões pendentes</label>
                    </div>
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" id="export-only-selected">
                        <label class="form-check-label" for="export-only-selected">Apenas itens selecionados</label>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
            </div>
        </div>
    </div>
</div>

<!-- Preview Changes Modal -->
<div class="modal fade" id="previewModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header" style="background: var(--ts-success); color: white;">
                <h5 class="modal-title"><i class="fas fa-eye me-2"></i>Preview das Alterações</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="alert alert-warning">
                    <i class="fas fa-exclamation-triangle me-2"></i>
                    Revise as alterações antes de aplicar. Esta ação <strong>atualizará seu anúncio no Mercado Livre</strong>.
                </div>

                <div id="preview-loading" class="text-center py-4">
                    <div class="spinner-border text-primary"></div>
                </div>

                <div id="preview-content" style="display: none;">
                    <h6 class="fw-bold mb-3">📝 Atributos que serão alterados:</h6>
                    <div id="preview-changes-list"></div>

                    <div class="row mt-4">
                        <div class="col-6 text-center">
                            <div class="p-3 bg-light rounded">
                                <div class="text-muted small">Score Atual</div>
                                <div class="fs-3 fw-bold" id="preview-score-before">-</div>
                            </div>
                        </div>
                        <div class="col-6 text-center">
                            <div class="p-3 bg-success bg-opacity-10 rounded">
                                <div class="text-muted small">Score Após</div>
                                <div class="fs-3 fw-bold text-success" id="preview-score-after">-</div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                <button type="button" class="btn btn-success" id="btn-confirm-apply" onclick="TechSheet.confirmApply()">
                    <i class="fas fa-check me-1"></i> Confirmar e Aplicar
                </button>
            </div>
        </div>
    </div>
</div>

<!-- SEO History / Rollback Modal -->
<div class="modal fade" id="seoHistoryModal" tabindex="-1">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fas fa-history me-2"></i>Histórico SEO & Rollback</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div id="seo-history-loading" class="text-center py-4">
                    <div class="spinner-border text-primary"></div>
                    <div class="small text-muted mt-2">Carregando histórico...</div>
                </div>
                <div id="seo-history-content" style="display:none;">
                    <div class="alert alert-info py-2 small">
                        <i class="fas fa-info-circle me-1"></i>
                        Use rollback para desfazer alterações de <strong>título</strong> e <strong>descrição</strong> aplicadas.
                    </div>
                    <div id="seo-history-list"></div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Fechar</button>
            </div>
        </div>
    </div>
</div>

<!-- SEO Title Optimize / Preview Modal -->
<div class="modal fade" id="seoTitleOptimizeModal" tabindex="-1">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fas fa-heading me-2"></i>Pré-visualizar Título (SEO)</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div id="seo-title-opt-loading" class="text-center py-4">
                    <div class="spinner-border text-primary"></div>
                    <div class="small text-muted mt-2">Gerando sugestão de título...</div>
                </div>

                <div id="seo-title-opt-content" style="display:none;">
                    <div class="mb-3">
                        <div class="small text-muted mb-1">Título atual</div>
                        <div class="p-2 bg-light border rounded small" id="seo-title-current"></div>
                    </div>

                    <div class="mb-2">
                        <label class="form-label fw-bold">Título otimizado (editável)</label>
                        <input type="text" class="form-control" id="seo-title-optimized-input" maxlength="60" placeholder="Digite o título final (máx 60 chars)">
                        <div class="d-flex justify-content-between mt-1">
                            <div class="small text-muted">Limite do ML: 60 caracteres</div>
                            <div class="small" id="seo-title-charcount">0/60</div>
                        </div>
                    </div>

                    <div class="mt-3">
                        <div class="small text-muted mb-1">Mudanças sugeridas</div>
                        <div id="seo-title-changes" class="small"></div>
                    </div>

                    <div class="mt-3">
                        <div class="small text-muted mb-1">Diferenças (preview)</div>
                        <div id="seo-title-diff" class="border rounded p-2 small" style="white-space: pre-wrap; font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, 'Liberation Mono', 'Courier New', monospace;"></div>
                        <div id="seo-title-nochange" class="text-muted small mt-1" style="display:none;">
                            <i class="fas fa-info-circle me-1"></i>
                            Sem mudanças detectadas. (Aplicar não fará diferença.)
                        </div>
                    </div>

                    <div class="alert alert-warning py-2 small mb-0">
                        <i class="fas fa-exclamation-triangle me-1"></i>
                        Ao aplicar, o anúncio será atualizado no Mercado Livre e será criado snapshot para rollback.
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" onclick="TechSheet.regenerateSEOTitle()">
                    <i class="fas fa-redo me-1"></i> Gerar novamente
                </button>
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Fechar</button>
                <button type="button" class="btn btn-success" id="seo-title-apply-btn" onclick="TechSheet.applySEOTitleFromModal()">
                    <i class="fas fa-check me-1"></i> Aplicar título
                </button>
            </div>
        </div>
    </div>
</div>

<!-- SEO Description Optimize / Preview Modal -->
<div class="modal fade" id="seoDescriptionOptimizeModal" tabindex="-1">
    <div class="modal-dialog modal-xl modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fas fa-align-left me-2"></i>Pré-visualizar Descrição (SEO)</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div id="seo-desc-opt-loading" class="text-center py-4">
                    <div class="spinner-border text-primary"></div>
                    <div class="small text-muted mt-2">Carregando descrição e gerando sugestão...</div>
                </div>

                <div id="seo-desc-opt-content" style="display:none;">
                    <div class="row g-2 mb-3">
                        <div class="col-12">
                            <div class="d-flex flex-wrap gap-2" id="seo-desc-stats"></div>
                        </div>
                    </div>

                    <div class="mb-3">
                        <button type="button" class="btn btn-sm btn-outline-secondary" onclick="TechSheet.toggleSEODescriptionDiff()">
                            <i class="fas fa-code-branch me-1"></i> Ver/Ocultar Diff
                        </button>
                        <div id="seo-desc-diff-wrap" class="mt-2">
                            <div class="small text-muted mb-1">Diferenças (preview)</div>
                            <pre id="seo-desc-diff" class="small mb-0 p-2 bg-light border rounded" style="white-space: pre-wrap; font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, 'Liberation Mono', 'Courier New', monospace;"></pre>
                            <div id="seo-desc-nochange" class="text-muted small mt-1" style="display:none;">
                                <i class="fas fa-info-circle me-1"></i>
                                Sem mudanças detectadas. (Aplicar não fará diferença.)
                            </div>
                        </div>
                    </div>
                    <div class="row g-3">
                        <div class="col-12 col-lg-6">
                            <div class="small text-muted mb-1">Descrição atual (somente leitura)</div>
                            <textarea class="form-control" id="seo-desc-current" rows="12" readonly></textarea>
                        </div>
                        <div class="col-12 col-lg-6">
                            <label class="form-label fw-bold">Descrição otimizada (editável)</label>
                            <textarea class="form-control" id="seo-desc-optimized-input" rows="12" placeholder="Edite a descrição final aqui..."></textarea>
                            <div class="d-flex justify-content-between mt-1">
                                <div class="small text-muted">Dica: mantenha bem estruturada (bullets + FAQs)</div>
                                <div class="small" id="seo-desc-charcount">0</div>
                            </div>
                        </div>
                    </div>

                    <div class="alert alert-warning py-2 small mt-3 mb-0">
                        <i class="fas fa-exclamation-triangle me-1"></i>
                        Ao aplicar, o anúncio será atualizado no Mercado Livre e será criado snapshot para rollback.
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" onclick="TechSheet.regenerateSEODescription()">
                    <i class="fas fa-redo me-1"></i> Gerar novamente
                </button>
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Fechar</button>
                <button type="button" class="btn btn-success" id="seo-desc-apply-btn" onclick="TechSheet.applySEODescriptionFromModal()">
                    <i class="fas fa-check me-1"></i> Aplicar descrição
                </button>
            </div>
        </div>
    </div>
</div>

<script nonce="<?= CSP_NONCE ?>">
    const requestJson = async (url, options = {}) => {
        if (window.ApiClient && typeof window.ApiClient.request === 'function') {
            return window.ApiClient.request(url, options);
        }

        const response = await fetch(url, {
            credentials: 'include',
            ...options
        });

        if (!response.ok) {
            const text = await response.text().catch(() => '');
            throw new Error(text || `Erro HTTP ${response.status}`);
        }

        return response.json();
    };

    const TechSheet = {
        state: {
            tab: 'all', // Começar mostrando todos os itens
            page: 1,
            perPage: 20,
            selected: new Set(),
            currentItem: null,
            searchTimeout: null,
            extractedAttributes: [],
            compareData: null,
            previewItemId: null,
            quickViewExpanded: true,
            quickViewItems: [],

            // SEO preview state
            seoTitleModalItemId: null,
            seoTitleOptimizeData: null,
            seoTitleCurrent: '',
            seoDescModalItemId: null,
            seoDescOptimizeData: null,
            seoDescCurrent: '',
        },

        // ======== SEO DIFF HELPERS ========

        toggleSEODescriptionDiff() {
            const wrap = document.getElementById('seo-desc-diff-wrap');
            if (!wrap) return;
            wrap.style.display = wrap.style.display === 'none' ? 'block' : 'none';
        },

        lcsDiff(a, b, maxLen = 120) {
            // LCS diff for arrays of tokens, capped for performance.
            const A = Array.isArray(a) ? a.slice(0, maxLen) : [];
            const B = Array.isArray(b) ? b.slice(0, maxLen) : [];

            const n = A.length;
            const m = B.length;
            const dp = Array.from({
                length: n + 1
            }, () => new Array(m + 1).fill(0));

            for (let i = 1; i <= n; i++) {
                for (let j = 1; j <= m; j++) {
                    if (A[i - 1] === B[j - 1]) dp[i][j] = dp[i - 1][j - 1] + 1;
                    else dp[i][j] = Math.max(dp[i - 1][j], dp[i][j - 1]);
                }
            }

            // Backtrack
            const ops = [];
            let i = n;
            let j = m;
            while (i > 0 && j > 0) {
                if (A[i - 1] === B[j - 1]) {
                    ops.push({
                        t: 'eq',
                        v: A[i - 1]
                    });
                    i--;
                    j--;
                } else if (dp[i - 1][j] >= dp[i][j - 1]) {
                    ops.push({
                        t: 'del',
                        v: A[i - 1]
                    });
                    i--;
                } else {
                    ops.push({
                        t: 'add',
                        v: B[j - 1]
                    });
                    j--;
                }
            }
            while (i > 0) {
                ops.push({
                    t: 'del',
                    v: A[i - 1]
                });
                i--;
            }
            while (j > 0) {
                ops.push({
                    t: 'add',
                    v: B[j - 1]
                });
                j--;
            }

            return ops.reverse();
        },

        renderTitleDiff(before, after) {
            const el = document.getElementById('seo-title-diff');
            if (!el) return;

            const noChangeEl = document.getElementById('seo-title-nochange');
            const applyBtn = document.getElementById('seo-title-apply-btn');

            const b = String(before || '').trim();
            const a = String(after || '').trim();

            const hasAfter = a.length > 0;
            const noChange = hasAfter && b.length > 0 && b === a;
            if (noChangeEl) noChangeEl.style.display = noChange ? 'block' : 'none';
            if (applyBtn) applyBtn.disabled = !hasAfter || a.length > 60 || noChange;

            if (!b && !a) {
                el.innerHTML = '<span class="text-muted">(vazio)</span>';
                return;
            }
            if (b === a) {
                el.innerHTML = '<span class="text-muted">Sem alterações.</span>';
                return;
            }

            const tokensA = b.split(/(\s+)/).filter(t => t !== '');
            const tokensB = a.split(/(\s+)/).filter(t => t !== '');
            const ops = this.lcsDiff(tokensA, tokensB, 180);

            const html = ops.map(op => {
                const v = this.escapeHtml(op.v);
                if (op.t === 'eq') return `<span>${v}</span>`;
                if (op.t === 'del') return `<span class="text-danger text-decoration-line-through">${v}</span>`;
                return `<span class="text-success fw-semibold">${v}</span>`;
            }).join('');

            el.innerHTML = html;
        },

        renderDescriptionDiff(before, after) {
            const el = document.getElementById('seo-desc-diff');
            if (!el) return;

            const noChangeEl = document.getElementById('seo-desc-nochange');
            const applyBtn = document.getElementById('seo-desc-apply-btn');

            const b = String(before || '');
            const a = String(after || '');

            const bNorm = b.replace(/\r\n/g, '\n').trim();
            const aNorm = a.replace(/\r\n/g, '\n').trim();
            const hasAfter = aNorm.length > 0;
            const noChange = hasAfter && bNorm.length > 0 && bNorm === aNorm;
            if (noChangeEl) noChangeEl.style.display = noChange ? 'block' : 'none';
            if (applyBtn) applyBtn.disabled = !hasAfter || noChange;

            if (!bNorm && !aNorm) {
                el.textContent = '(vazio)';
                return;
            }
            if (bNorm === aNorm) {
                el.textContent = 'Sem alterações.';
                return;
            }

            // Line-based diff (limited)
            const linesA = b.replace(/\r\n/g, '\n').split('\n');
            const linesB = a.replace(/\r\n/g, '\n').split('\n');
            const ops = this.lcsDiff(linesA, linesB, 160);

            const out = [];
            let shown = 0;
            for (const op of ops) {
                if (shown >= 240) break;
                if (op.t === 'eq') {
                    // keep a small context only
                    if (op.v.trim() !== '') {
                        out.push(`  ${op.v}`);
                        shown++;
                    }
                } else if (op.t === 'del') {
                    out.push(`- ${op.v}`);
                    shown++;
                } else if (op.t === 'add') {
                    out.push(`+ ${op.v}`);
                    shown++;
                }
            }

            el.textContent = out.join('\n');
        },

        async init() {
            // Definir ordenação padrão para mostrar itens com mais lacunas primeiro
            document.getElementById('filter-sort').value = 'missing_required';

            // Selecionar a aba "Todos" como padrão
            document.querySelectorAll('.tab-pill').forEach(btn => {
                btn.classList.toggle('active', btn.dataset.tab === 'all');
            });

            await this.loadStats();
            await this.loadQuickView();
            await this.loadList();
        },

        // Quick View - Carrega os itens mais críticos
        async loadQuickView() {
            const content = document.getElementById('quick-view-content');
            const summary = document.getElementById('quick-view-summary');
            const section = document.getElementById('quick-view-section');

            try {
                // Buscar itens com mais lacunas (ordenados por total de faltantes)
                const data = await requestJson('/api/seo/technical-sheet/items?per_page=12&sort=total_gaps&tab=pending');

                // Se não há conta conectada, esconder quick view
                if (!data.success && data.error && data.error.includes('conta')) {
                    section.style.display = 'none';
                    return;
                }

                section.style.display = 'block';

                if (data.success && data.items?.length > 0) {
                    this.state.quickViewItems = data.items;
                    this.renderQuickView(data.items);

                    // Calcular totais para o resumo
                    const totalGaps = data.items.reduce((sum, item) => {
                        return sum + (item.missing_required || 0) + (item.missing_filter || 0) + (item.missing_hidden || 0);
                    }, 0);
                    summary.textContent = `${data.items.length} itens · ${totalGaps} lacunas`;
                } else {
                    content.innerHTML = `
                    <div class="quick-view-empty">
                        <i class="fas fa-check-circle fa-2x text-success mb-2"></i>
                        <p class="mb-0">🎉 Parabéns! Todos os anúncios estão com atributos completos.</p>
                    </div>
                `;
                    summary.textContent = 'Tudo completo!';
                }
            } catch (e) {
                console.error('Error loading quick view:', e);
                content.innerHTML = `
                <div class="quick-view-empty text-danger">
                    <i class="fas fa-exclamation-triangle"></i> Erro ao carregar
                </div>
            `;
            }
        },

        renderQuickView(items) {
            const content = document.getElementById('quick-view-content');

            const html = items.map(item => {
                const totalGaps = (item.missing_required || 0) + (item.missing_filter || 0) + (item.missing_hidden || 0);
                const urgencyClass = totalGaps >= 5 ? 'critical' : totalGaps >= 3 ? 'medium' : 'low';
                const totalClass = totalGaps >= 5 ? '' : totalGaps >= 3 ? 'medium' : 'low';

                return `
                <div class="quick-item-card ${urgencyClass}" onclick="TechSheet.openDrawer('${item.item_id}')" title="Clique para ver detalhes">
                    <div class="quick-item-title">${this.escapeHtml(item.title || 'Sem título')}</div>
                    <div class="quick-item-id">${item.item_id}</div>
                    <div class="quick-item-gaps">
                        <div class="quick-total-gaps ${totalClass}">${totalGaps}</div>
                        <div class="quick-gaps-breakdown">
                            ${item.missing_required ? `<span class="quick-gap-pill critical" title="Obrigatórios">⚠️ ${item.missing_required}</span>` : ''}
                            ${item.missing_filter ? `<span class="quick-gap-pill filter" title="Filtros">🔍 ${item.missing_filter}</span>` : ''}
                            ${item.missing_hidden ? `<span class="quick-gap-pill hidden" title="Hidden SEO">🔮 ${item.missing_hidden}</span>` : ''}
                        </div>
                    </div>
                </div>
            `;
            }).join('');

            content.innerHTML = `<div class="quick-view-grid">${html}</div>`;
        },

        toggleQuickView() {
            this.state.quickViewExpanded = !this.state.quickViewExpanded;
            const content = document.getElementById('quick-view-content');
            const toggle = document.getElementById('quick-view-toggle');

            if (this.state.quickViewExpanded) {
                content.style.display = 'block';
                toggle.innerHTML = '<i class="fas fa-chevron-up"></i> Ocultar';
            } else {
                content.style.display = 'none';
                toggle.innerHTML = '<i class="fas fa-chevron-down"></i> Mostrar';
            }
        },

        async loadStats() {
            try {
                const params = new URLSearchParams();
                if (this.state.tab && this.state.tab !== 'all') {
                    params.set('tab', this.state.tab);
                }

                const data = await requestJson(`/api/seo/technical-sheet/stats?${params}`);

                if (data.success) {
                    document.getElementById('kpi-total').textContent = data.total_items || 0;
                    document.getElementById('kpi-critical').textContent = data.critical_gap_items || 0;
                    document.getElementById('kpi-hidden').textContent = data.hidden_gap_items || 0;
                    if (typeof TechSheet.renderHiddenBanner === 'function') {
                        TechSheet.renderHiddenBanner(data);
                    }
                    document.getElementById('kpi-pending').textContent = data.pending_suggestions_total || 0;

                    const avgCompl = data.avg_completeness_analyzed;
                    document.getElementById('kpi-completeness').textContent = avgCompl !== null ? avgCompl.toFixed(1) + '%' : '-';

                    // MODEL suggestions KPI
                    const modelStats = data.model_suggestions || {};
                    const modelKpi = document.getElementById('kpi-model');
                    if (modelKpi) {
                        const coverage = modelStats.coverage_percent ?? 0;
                        const withSugg = modelStats.items_with_suggestions ?? 0;
                        const needing = modelStats.items_needing_model ?? 0;
                        modelKpi.textContent = `${withSugg}/${needing}`;
                        modelKpi.title = `${coverage}% cobertura - ${modelStats.total_suggestions || 0} sugestões totais`;
                    }

                    // Update tab badges
                    document.getElementById('tab-pending-count').textContent = data.pending_gap_items || data.critical_gap_items || 0;
                    document.getElementById('tab-hidden-count').textContent = data.hidden_gap_items || 0;
                    document.getElementById('tab-review-count').textContent = data.items_with_pending_suggestions || 0;
                }
                // Load Smart Fill Widget
                this.loadSmartFillWidget();
            } catch (e) {
                console.error('Error loading stats:', e);
            }
        },

        async loadSmartFillWidget() {
            try {
                const data = await requestJson('/api/seo/technical-sheet/smart-fill/widget');

                if (data.success && data.widget) {
                    const widget = data.widget;
                    const widgetEl = document.getElementById('smart-fill-widget');

                    document.getElementById('sf-pending').textContent = widget.total_pending || 0;
                    document.getElementById('sf-applied').textContent = widget.total_applied || 0;
                    document.getElementById('sf-confidence').textContent = widget.avg_confidence || 0;
                    document.getElementById('sf-coverage').textContent = widget.coverage_rate || 0;
                    document.getElementById('sf-action').textContent = widget.action_needed || '';

                    widgetEl.style.display = 'block';
                }
            } catch (e) {
                console.error('Error loading Smart Fill widget:', e);
            }
        },

        async openSmartFillDashboard() {
            const modal = new bootstrap.Modal(document.getElementById('smartFillDashboardModal') || this.createSmartFillDashboardModal());
            modal.show();
            await this.loadSmartFillDashboardData();
        },

        createSmartFillDashboardModal() {
            const modalHtml = `
        <div class="modal fade" id="smartFillDashboardModal" tabindex="-1">
            <div class="modal-dialog modal-xl">
                <div class="modal-content">
                    <div class="modal-header" style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white;">
                        <h5 class="modal-title"><i class="fas fa-chart-pie me-2"></i>Smart Fill Dashboard</h5>
                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <div id="sf-dashboard-loading" class="text-center py-5">
                            <div class="spinner-border text-primary"></div>
                            <p class="mt-2 text-muted">Carregando métricas...</p>
                        </div>
                        <div id="sf-dashboard-content" style="display: none;">
                            <!-- Summary Cards -->
                            <div class="row mb-4" id="sf-summary-cards"></div>

                            <!-- Charts Row -->
                            <div class="row">
                                <div class="col-md-6 mb-4">
                                    <div class="card h-100">
                                        <div class="card-header"><i class="fas fa-chart-pie me-2"></i>Sugestões por Fonte</div>
                                        <div class="card-body">
                                            <canvas id="sf-chart-sources" height="250"></canvas>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-md-6 mb-4">
                                    <div class="card h-100">
                                        <div class="card-header"><i class="fas fa-chart-bar me-2"></i>Taxa de Sucesso por Confiança</div>
                                        <div class="card-body">
                                            <canvas id="sf-chart-success" height="250"></canvas>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- Trend Chart -->
                            <div class="card mb-4">
                                <div class="card-header"><i class="fas fa-chart-line me-2"></i>Tendência (14 dias)</div>
                                <div class="card-body">
                                    <canvas id="sf-chart-trend" height="150"></canvas>
                                </div>
                            </div>

                            <!-- Top Attributes -->
                            <div class="card">
                                <div class="card-header"><i class="fas fa-list-ol me-2"></i>Top 10 Atributos Preenchidos</div>
                                <div class="card-body">
                                    <canvas id="sf-chart-attributes" height="200"></canvas>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>`;

            document.body.insertAdjacentHTML('beforeend', modalHtml);
            return document.getElementById('smartFillDashboardModal');
        },

        async loadSmartFillDashboardData() {
            try {
                const data = await requestJson('/api/seo/technical-sheet/smart-fill/dashboard');

                if (!data.success) throw new Error(data.error || 'Erro ao carregar dashboard');

                document.getElementById('sf-dashboard-loading').style.display = 'none';
                document.getElementById('sf-dashboard-content').style.display = 'block';

                const d = data.data;

                // Summary Cards
                const summary = d.summary;
                document.getElementById('sf-summary-cards').innerHTML = `
                <div class="col-md-3">
                    <div class="card text-center border-0 shadow-sm">
                        <div class="card-body">
                            <h3 class="text-primary">${summary.total_suggestions}</h3>
                            <small class="text-muted">Total Sugestões</small>
                        </div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="card text-center border-0 shadow-sm">
                        <div class="card-body">
                            <h3 class="text-warning">${summary.pending}</h3>
                            <small class="text-muted">Pendentes</small>
                        </div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="card text-center border-0 shadow-sm">
                        <div class="card-body">
                            <h3 class="text-success">${summary.applied}</h3>
                            <small class="text-muted">Aplicadas</small>
                        </div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="card text-center border-0 shadow-sm">
                        <div class="card-body">
                            <h3 class="text-info">${summary.avg_confidence}%</h3>
                            <small class="text-muted">Confiança Média</small>
                        </div>
                    </div>
                </div>
            `;

                // Sources Chart
                if (d.by_source?.chart) {
                    new Chart(document.getElementById('sf-chart-sources'), {
                        type: 'doughnut',
                        data: d.by_source.chart,
                        options: {
                            plugins: {
                                legend: {
                                    position: 'right'
                                }
                            }
                        }
                    });
                }

                // Success Rate Chart
                if (d.success_rate?.chart) {
                    new Chart(document.getElementById('sf-chart-success'), {
                        type: 'bar',
                        data: d.success_rate.chart,
                        options: {
                            indexAxis: 'y',
                            plugins: {
                                legend: {
                                    display: false
                                }
                            },
                            scales: {
                                x: {
                                    max: 100,
                                    title: {
                                        display: true,
                                        text: 'Taxa de Aceitação (%)'
                                    }
                                }
                            }
                        }
                    });
                }

                // Trend Chart
                if (d.trend) {
                    new Chart(document.getElementById('sf-chart-trend'), {
                        type: 'line',
                        data: d.trend,
                        options: {
                            plugins: {
                                legend: {
                                    position: 'top'
                                }
                            }
                        }
                    });
                }

                // Top Attributes Chart
                if (d.top_attributes) {
                    new Chart(document.getElementById('sf-chart-attributes'), {
                        type: 'bar',
                        data: d.top_attributes,
                        options: {
                            indexAxis: 'y',
                            plugins: {
                                legend: {
                                    position: 'top'
                                }
                            }
                        }
                    });
                }

            } catch (e) {
                console.error('Error loading Smart Fill dashboard:', e);
                document.getElementById('sf-dashboard-loading').innerHTML = `
                <div class="alert alert-danger"><i class="fas fa-exclamation-triangle me-2"></i>${e.message}</div>
            `;
            }
        },

        async autoApproveSmartFill() {
            const threshold = await Swal.fire({
                title: '🎯 Auto-Aprovar Sugestões',
                html: `
                <p>Aprovar automaticamente sugestões com confiança alta.</p>
                <div class="mb-3">
                    <label class="form-label">Confiança mínima (%)</label>
                    <input type="number" id="swal-threshold" class="form-control" value="85" min="50" max="100">
                </div>
                <div class="mb-3">
                    <label class="form-label">Limite de sugestões</label>
                    <input type="number" id="swal-limit" class="form-control" value="100" min="1" max="500">
                </div>
            `,
                showCancelButton: true,
                confirmButtonText: 'Aprovar',
                cancelButtonText: 'Cancelar',
                preConfirm: () => ({
                    threshold: parseInt(document.getElementById('swal-threshold').value),
                    limit: parseInt(document.getElementById('swal-limit').value)
                })
            });

            if (!threshold.isConfirmed) return;

            try {
                const data = await requestJson('/api/seo/technical-sheet/smart-fill/auto-approve', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json'
                    },
                    body: JSON.stringify(threshold.value)
                });

                if (data.success) {
                    Swal.fire({
                        icon: 'success',
                        title: 'Sugestões Aprovadas!',
                        html: `<strong>${data.approved_count}</strong> sugestões com confiança ≥ ${data.threshold}% foram aprovadas.`,
                        timer: 3000
                    });
                    this.loadStats();
                } else {
                    throw new Error(data.message || 'Erro desconhecido');
                }
            } catch (e) {
                Swal.fire('Erro', e.message, 'error');
            }
        },

        async loadList() {
            const loading = document.getElementById('table-loading');
            loading.classList.add('active');

            try {
                const params = new URLSearchParams();
                params.set('page', this.state.page);
                params.set('per_page', this.state.perPage);

                if (this.state.tab && this.state.tab !== 'all') {
                    params.set('tab', this.state.tab);
                }

                const search = document.getElementById('filter-search').value.trim();
                if (search) {
                    if (search.startsWith('MLB')) {
                        params.set('item_id', search);
                    } else {
                        params.set('q', search);
                    }
                }

                const category = document.getElementById('filter-category').value;
                if (category) params.set('category_id', category);

                const sort = document.getElementById('filter-sort').value;
                if (sort) params.set('sort', sort);

                const data = await requestJson(`/api/seo/technical-sheet/items?${params}`);

                if (data.success) {
                    this.renderTable(data.items || []);
                    this.updatePagination(data.pagination || {});
                } else {
                    // Verificar se é erro de conta
                    if (data.error && data.error.includes('conta')) {
                        this.showNoAccountError();
                    } else {
                        this.showError(data.error || 'Erro ao carregar itens');
                    }
                }
            } catch (e) {
                console.error('Error loading list:', e);
                this.showError('Erro de conexão');
            } finally {
                loading.classList.remove('active');
            }
        },

        showNoAccountError() {
            const tbody = document.getElementById('items-tbody');
            const empty = document.getElementById('empty-state');
            const quickView = document.getElementById('quick-view-section');

            // Esconder quick view quando não há conta
            if (quickView) quickView.style.display = 'none';

            tbody.innerHTML = '';
            empty.innerHTML = `
            <i class="fas fa-user-circle"></i>
            <h5>👆 Selecione uma Conta</h5>
            <p>Para visualizar a ficha técnica dos seus anúncios, selecione uma conta do Mercado Livre no topo da página.</p>
            <button class="btn btn-primary mt-2" onclick="AccountSelector.openModal()">
                <i class="fas fa-exchange-alt me-1"></i> Selecionar Conta
            </button>
        `;
            empty.style.display = 'block';

            // Resetar KPIs
            document.getElementById('kpi-total').textContent = '-';
            document.getElementById('kpi-critical').textContent = '-';
            document.getElementById('kpi-hidden').textContent = '-';
            document.getElementById('kpi-pending').textContent = '-';
            document.getElementById('kpi-completeness').textContent = '-';
        },

        renderTable(items) {
            const tbody = document.getElementById('items-tbody');
            const empty = document.getElementById('empty-state');
            const quickView = document.getElementById('quick-view-section');

            // Restaurar estado padrão do empty-state
            empty.innerHTML = `
            <i class="fas fa-inbox"></i>
            <h5>Nenhum item encontrado</h5>
            <p>Tente ajustar os filtros ou sincronize seus anúncios</p>
        `;

            if (!items.length) {
                tbody.innerHTML = '';
                empty.style.display = 'block';
                return;
            }

            // Mostrar quick view quando há itens
            if (quickView) quickView.style.display = 'block';

            empty.style.display = 'none';

            tbody.innerHTML = items.map(item => {
                const completeness = parseFloat(item.completeness_percent) || 0;
                const compClass = completeness < 40 ? 'critical' : completeness < 70 ? 'warning' : 'good';

                const missingReq = item.missing_required || 0;
                const missingFilter = item.missing_filter || 0;
                const missingHidden = item.missing_hidden || 0;
                const missingRec = item.missing_recommended || 0;

                const pending = item.pending_suggestions_count || 0;
                const approved = item.approved_suggestions_count || 0;

                const isSelected = this.state.selected.has(item.item_id);

                // Calcular SEO Priority Score (0-100)
                const seoScore = this.calculateSeoScore(completeness, missingReq, missingFilter, missingHidden);
                const seoClass = seoScore >= 80 ? 'excellent' : seoScore >= 60 ? 'good' : seoScore >= 40 ? 'warning' : 'critical';

                // Total de lacunas
                const totalGaps = missingReq + missingFilter + missingHidden;

                return `
                <tr data-item-id="${item.item_id}" data-category-id="${item.category_id || ''}" class="${missingHidden > 0 ? 'has-hidden-gap' : ''}">
                    <td>
                        <input type="checkbox" class="form-check-input item-checkbox"
                               data-id="${item.item_id}"
                               ${isSelected ? 'checked' : ''}
                               onchange="TechSheet.toggleSelect('${item.item_id}', this.checked)">
                    </td>
                    <td>
                        <div class="d-flex align-items-start gap-2">
                            <div class="seo-score-mini ${seoClass}" title="SEO Score: ${seoScore}">
                                ${seoScore}
                            </div>
                            <div class="flex-grow-1">
                                <div class="fw-semibold text-truncate" style="max-width: 240px;" title="${this.escapeHtml(item.title)}">
                                    ${this.escapeHtml(item.title || 'Sem título')}
                                </div>
                                <div class="item-meta">
                                    <span class="text-muted">${item.item_id}</span>
                                    ${item.category_id ? `<span class="category-badge">${item.category_id}</span>` : ''}
                                </div>
                            </div>
                        </div>
                    </td>
                    <td class="total-gaps-cell">
                        <div class="total-gaps-badge ${totalGaps >= 5 ? 'critical' : totalGaps >= 2 ? 'warning' : 'good'}" title="Total: ${totalGaps} atributos faltantes">
                            ${totalGaps === 0 ? '✓' : totalGaps}
                        </div>
                    </td>
                    <td>
                        <div class="d-flex align-items-center gap-2">
                            <div class="completeness-bar flex-grow-1" title="Completude: ${completeness.toFixed(1)}%">
                                <div class="fill ${compClass}" style="width: ${completeness}%"></div>
                            </div>
                            <span class="fw-semibold" style="min-width: 45px;">${completeness.toFixed(0)}%</span>
                        </div>
                    </td>
                    <td>
                        <div class="gaps-container">
                            ${missingReq ? `
                                <span class="gaps-badge critical" title="${missingReq} atributo(s) obrigatório(s) faltando - CRÍTICO">
                                    <i class="fas fa-exclamation-circle"></i> ${missingReq} obrig.
                                </span>
                            ` : ''}
                            ${missingFilter ? `
                                <span class="gaps-badge high" title="${missingFilter} atributo(s) de filtro faltando">
                                    <i class="fas fa-filter"></i> ${missingFilter} filtro
                                </span>
                            ` : ''}
                            ${missingHidden ? `
                                <span class="gaps-badge hidden" title="${missingHidden} atributo(s) oculto(s) de SEO">
                                    🔮 ${missingHidden} hidden
                                </span>
                            ` : ''}
                            ${totalGaps === 0 ? '<span class="complete-badge"><i class="fas fa-check-circle"></i> Completo</span>' : ''}
                        </div>
                    </td>
                    <td>
                        <div class="suggestions-container">
                            ${pending ? `<span class="badge bg-warning text-dark" title="${pending} sugestão(ões) aguardando revisão">${pending} pend.</span>` : ''}
                            ${approved ? `<span class="badge bg-info" title="${approved} sugestão(ões) aprovada(s) - pronta(s) para aplicar">${approved} aprov.</span>` : ''}
                            ${!pending && !approved ? '<span class="text-muted">-</span>' : ''}
                        </div>
                    </td>
                    <td>
                        <div class="action-buttons">
                            <button class="btn-action primary" onclick="TechSheet.openDrawer('${item.item_id}')" title="Ver detalhes">
                                <i class="fas fa-eye"></i>
                            </button>
                            <button class="btn-action secondary" onclick="TechSheet.quickGenerate('${item.item_id}')" title="Gerar sugestões">
                                <i class="fas fa-magic"></i>
                            </button>
                            <div class="action-dropdown">
                                <button class="action-dropdown-btn" onclick="TechSheet.toggleDropdown(event, '${item.item_id}')" title="Mais ações">
                                    <i class="fas fa-ellipsis-v"></i>
                                </button>
                                <div class="action-dropdown-menu" id="dropdown-${item.item_id}">
                                    <div class="action-dropdown-item" onclick="TechSheet.refreshItem('${item.item_id}')">
                                        <i class="fas fa-sync-alt text-primary"></i> Atualizar da API
                                    </div>
                                    <div class="action-dropdown-item success" onclick="TechSheet.quickSuggestions('${item.item_id}')">
                                        <i class="fas fa-bolt text-success"></i> Sugestões Rápidas
                                    </div>
                                    <div class="action-dropdown-item gradient-purple" onclick="TechSheet.smartFillGaps('${item.item_id}')">
                                        <i class="fas fa-brain text-purple"></i> 🎯 Smart Fill (SEO)
                                    </div>
                                    <div class="action-dropdown-item purple" onclick="TechSheet.modelSuggestions('${item.item_id}')">
                                        <i class="fas fa-search-plus text-purple"></i> Sugestões Modelo
                                    </div>
                                    <div class="action-dropdown-item info" onclick="TechSheet.mineKeywords('${item.item_id}', '${item.category_id}')">
                                        <i class="fas fa-gem text-info"></i> Minerar Keywords
                                    </div>
                                    <div class="action-dropdown-item" onclick="TechSheet.openExtractModal('${item.item_id}')">
                                        <i class="fas fa-font"></i> Extrair do Título
                                    </div>
                                    <div class="action-dropdown-item purple" onclick="TechSheet.openCompareModal('${item.item_id}')">
                                        <i class="fas fa-balance-scale"></i> Comparar Concorrentes
                                    </div>
                                    <div class="action-dropdown-item warning" onclick="TechSheet.openMarketAnalysis('${item.category_id}')">
                                        <i class="fas fa-chart-line text-warning"></i> Análise de Mercado
                                    </div>
                                    <div class="action-dropdown-item info" onclick="TechSheet.analyzeQuality('${item.item_id}')">
                                        <i class="fas fa-star text-info"></i> Qualidade do Anúncio
                                    </div>
                                    <div class="action-dropdown-divider"></div>
                                    <div class="action-dropdown-item success" onclick="TechSheet.openPreviewModal('${item.item_id}')">
                                        <i class="fas fa-eye"></i> Preview & Aplicar
                                    </div>
                                    <div class="action-dropdown-item" onclick="window.open(ML.itemUrl('${item.item_id}'), '_blank')">
                                        <i class="fas fa-external-link-alt"></i> Ver no ML
                                    </div>
                                </div>
                            </div>
                        </div>
                    </td>
                </tr>
            `;
            }).join('');
        },

        updatePagination(pagination) {
            const {
                page = 1, per_page = 20, total = 0, pages = 1
            } = pagination;

            const start = Math.min((page - 1) * per_page + 1, total);
            const end = Math.min(page * per_page, total);

            document.getElementById('page-showing').textContent = total > 0 ? `${start}-${end}` : '0';
            document.getElementById('page-total').textContent = total;

            document.getElementById('btn-prev').disabled = page <= 1;
            document.getElementById('btn-next').disabled = page >= pages;

            this.state.page = page;
        },

        setTab(tab) {
            this.state.tab = tab;
            this.state.page = 1;

            document.querySelectorAll('.tab-pill').forEach(btn => {
                btn.classList.toggle('active', btn.dataset.tab === tab);
            });

            this.loadStats();
            this.loadList();
        },

        prevPage() {
            if (this.state.page > 1) {
                this.state.page--;
                this.loadList();
            }
        },

        nextPage() {
            this.state.page++;
            this.loadList();
        },

        debounceSearch(e) {
            clearTimeout(this.state.searchTimeout);
            this.state.searchTimeout = setTimeout(() => {
                this.state.page = 1;
                this.loadList();
            }, 400);
        },

        toggleSelect(itemId, checked) {
            if (checked) {
                this.state.selected.add(itemId);
            } else {
                this.state.selected.delete(itemId);
            }
            this.updateBulkBar();
        },

        toggleSelectAll(checked) {
            const checkboxes = document.querySelectorAll('.item-checkbox');
            checkboxes.forEach(cb => {
                const id = cb.dataset.id;
                cb.checked = checked;
                if (checked) {
                    this.state.selected.add(id);
                } else {
                    this.state.selected.delete(id);
                }
            });
            this.updateBulkBar();
        },

        clearSelection() {
            this.state.selected.clear();
            document.querySelectorAll('.item-checkbox').forEach(cb => cb.checked = false);
            document.getElementById('select-all').checked = false;
            this.updateBulkBar();
        },

        updateBulkBar() {
            const bar = document.getElementById('bulk-bar');
            const count = this.state.selected.size;
            document.getElementById('selected-count').textContent = count;
            bar.classList.toggle('active', count > 0);
        },

        async openDrawer(itemId) {
            const drawer = document.getElementById('item-drawer');
            const backdrop = document.getElementById('drawer-backdrop');
            const body = document.getElementById('drawer-body');

            body.innerHTML = '<div class="text-center py-4"><div class="spinner"></div></div>';
            drawer.classList.add('open');
            backdrop.classList.add('open');

            try {
                const data = await requestJson(`/api/seo/technical-sheet/items/${encodeURIComponent(itemId)}`);
                if (data.success) {
                    this.state.currentItem = data;
                    this.renderDrawer(data);
                } else {
                    body.innerHTML = `<div class="alert alert-danger">${data.error || 'Erro ao carregar item'}</div>`;
                }
            } catch (e) {
                body.innerHTML = '<div class="alert alert-danger">Erro de conexão</div>';
            }
        },

        renderDrawer(data) {
            const body = document.getElementById('drawer-body');
            const item = data.item || {};
            const summary = data.summary || {};
            const gaps = data.gaps || {};
            const suggestions = data.suggestions || [];

            const completeness = parseFloat(summary.completeness_percent || gaps.completeness) || 0;

            let html = `
            <div class="mb-4">
                <h6 class="fw-semibold">${this.escapeHtml(item.title || 'Sem título')}</h6>
                <small class="text-muted">${item.item_id} · ${item.category_id || '-'}</small>
            </div>

            <div class="row g-2 mb-4">
                <div class="col-6">
                    <div class="kpi-card">
                        <div class="value" style="font-size: 1.5rem;">${completeness.toFixed(0)}%</div>
                        <div class="label">Completude</div>
                    </div>
                </div>
                <div class="col-6">
                    <div class="kpi-card danger">
                        <div class="value" style="font-size: 1.5rem;">${summary.missing_required || 0}</div>
                        <div class="label">Lacunas Críticas</div>
                    </div>
                </div>
                <div class="col-6">
                    <div class="kpi-card hidden">
                        <div class="value" style="font-size: 1.5rem;">${summary.missing_hidden || (gaps.gaps?.hidden?.length || 0)}</div>
                        <div class="label">🔮 Hidden SEO</div>
                    </div>
                </div>
            </div>
        `;

            // Hidden SEO Attributes Section (highlight these special attributes)
            const hiddenGaps = gaps.gaps?.hidden || [];
            if (hiddenGaps.length > 0) {
                html += `
                <div class="hidden-seo-section">
                    <div class="d-flex align-items-center gap-2 mb-3">
                        <span class="hidden-seo-badge">🔮 Hidden SEO Boost</span>
                        <span class="text-muted small">Atributos que melhoram ranking sem aparecer na ficha</span>
                    </div>
                    ${hiddenGaps.map(gap => {
                        const suggestion = suggestions.find(s => s.attribute_id === gap.id);
                        return `
                            <div class="hidden-attr-card">
                                <div class="d-flex justify-content-between align-items-start">
                                    <div>
                                        <div class="attr-name">${this.escapeHtml(gap.name)}</div>
                                        <div class="seo-impact">⚡ Aumenta visibilidade na busca</div>
                                    </div>
                                    ${suggestion ? this.renderConfidenceBadge(suggestion.confidence) : ''}
                                </div>
                                ${suggestion ? `
                                    <div class="mt-2 p-2 bg-light rounded">
                                        <small class="text-muted">Sugestão:</small>
                                        <div class="fw-semibold">${this.escapeHtml(suggestion.suggested_value)}</div>
                                        <small class="text-muted">Fonte: ${this.escapeHtml(this.sourceLabel(suggestion.source))} · Status: ${suggestion.status}</small>
                                        ${suggestion.status === 'pending' ? `
                                            <div class="mt-2 d-flex gap-2">
                                                <button class="btn btn-sm btn-success" onclick="TechSheet.decideSuggestion('${item.item_id}', '${gap.id}', 'approved')">
                                                    <i class="fas fa-check"></i> Aprovar
                                                </button>
                                                <button class="btn btn-sm btn-outline-danger" onclick="TechSheet.decideSuggestion('${item.item_id}', '${gap.id}', 'rejected')">
                                                    <i class="fas fa-times"></i>
                                                </button>
                                            </div>
                                        ` : `<small class="text-muted">Status: ${suggestion.status}</small>`}
                                    </div>
                                ` : `
                                    <div class="mt-2 text-muted small">
                                        <i class="fas fa-lightbulb"></i> Clique em "Gerar Sugestões" para preencher
                                    </div>
                                `}
                            </div>
                        `;
                    }).join('')}
                </div>
            `;
            }

            // Regular Gaps section (required, filter, recommended)
            const regularGaps = [
                ...(gaps.gaps?.required || []).map(g => ({
                    ...g,
                    type: 'critical'
                })),
                ...(gaps.gaps?.filter || []).map(g => ({
                    ...g,
                    type: 'high'
                })),
                ...(gaps.gaps?.recommended || []).slice(0, 5).map(g => ({
                    ...g,
                    type: 'low'
                })),
            ];

            if (regularGaps.length) {
                html += `<h6 class="fw-semibold mb-3 mt-4">📋 Outras Lacunas (${regularGaps.length})</h6>`;

                regularGaps.slice(0, 15).forEach(gap => {
                    const suggestion = suggestions.find(s => s.attribute_id === gap.id);

                    html += `
                    <div class="gap-item ${gap.type}">
                        <div class="gap-header">
                            <span class="gap-name">${this.escapeHtml(gap.name)}</span>
                            <span class="gaps-badge ${gap.type}">${gap.priority || gap.type}</span>
                        </div>
                        <small class="text-muted">ID: ${gap.id}</small>

                        ${suggestion ? `
                            <div class="suggestion-card">
                                <div class="d-flex justify-content-between align-items-center">
                                    <span class="suggestion-value">${this.escapeHtml(suggestion.suggested_value)}</span>
                                    ${this.renderConfidenceBadge(suggestion.confidence)}
                                </div>
                                <div class="suggestion-meta">
                                    Fonte: ${this.escapeHtml(this.sourceLabel(suggestion.source))} · Status: ${suggestion.status}
                                </div>
                                ${suggestion.status === 'pending' ? `
                                    <div class="mt-2 d-flex gap-2">
                                        <button class="btn btn-sm btn-outline-success" onclick="TechSheet.decideSuggestion('${item.item_id}', '${gap.id}', 'approved')">
                                            <i class="fas fa-check"></i> Aprovar
                                        </button>
                                        <button class="btn btn-sm btn-outline-danger" onclick="TechSheet.decideSuggestion('${item.item_id}', '${gap.id}', 'rejected')">
                                            <i class="fas fa-times"></i> Rejeitar
                                        </button>
                                    </div>
                                ` : ''}
                            </div>
                        ` : `
                            <div class="text-muted small mt-1">
                                <i class="fas fa-info-circle"></i> Sem sugestão - clique em "Gerar"
                            </div>
                        `}
                    </div>
                `;
                });
            }

            // Show success message only if no gaps at all
            if (!regularGaps.length && !hiddenGaps.length) {
                html += `
                <div class="text-center text-success py-4">
                    <i class="fas fa-check-circle fa-2x mb-2"></i>
                    <p class="mb-0">Todos os atributos preenchidos!</p>
                </div>
            `;
            }

            // Pending suggestions summary
            const pendingSuggestions = suggestions.filter(s => s.status === 'pending');
            const approvedSuggestions = suggestions.filter(s => s.status === 'approved');

            if (pendingSuggestions.length || approvedSuggestions.length) {
                html += `
                <hr class="my-4">
                <h6 class="fw-semibold mb-3">💡 Resumo de Sugestões</h6>
                <div class="row g-2">
                    <div class="col-6">
                        <div class="text-center p-3 bg-light rounded">
                            <div class="fw-bold text-warning">${pendingSuggestions.length}</div>
                            <small>Pendentes</small>
                        </div>
                    </div>
                    <div class="col-6">
                        <div class="text-center p-3 bg-light rounded">
                            <div class="fw-bold text-success">${approvedSuggestions.length}</div>
                            <small>Aprovadas</small>
                        </div>
                    </div>
                </div>
            `;
            }

            // SEO Strategies Panel
            html += `
            <hr class="my-4">
            <div class="seo-strategies-panel">
                <div class="d-flex align-items-center justify-content-between mb-3">
                    <h6 class="fw-semibold mb-0">
                        <i class="fas fa-rocket text-primary"></i> SEO Strategies
                    </h6>
                    <button class="btn btn-sm btn-outline-primary" onclick="TechSheet.loadSEOAnalysis('${item.item_id}')">
                        <i class="fas fa-sync-alt"></i> Analisar
                    </button>
                </div>
                <div id="seo-analysis-container" class="seo-analysis-container">
                    <div class="text-center text-muted py-3">
                        <i class="fas fa-chart-line fa-2x mb-2 opacity-50"></i>
                        <p class="mb-0 small">Clique em "Analisar" para ver o diagnóstico SEO completo</p>
                    </div>
                </div>
            </div>
        `;

            body.innerHTML = html;
            document.getElementById('drawer-title').textContent = item.item_id || 'Item';
        },

        renderConfidenceBadge(confidence) {
            if (!confidence) return '';
            const cls = confidence >= 85 ? 'high' : confidence >= 70 ? 'medium' : 'low';
            return `<span class="confidence-badge ${cls}">${confidence}%</span>`;
        },

        sourceLabel(source) {
            const map = {
                ean_pool: 'EAN do pool',
                empty_gtin_policy: 'Motivo sem GTIN',
                hidden_seo: 'Hidden SEO',
                title: 'Título',
                title_extraction: 'Título',
                benchmark: 'Benchmark',
                ai: 'IA',
                inference: 'Inferência',
                default: 'Padrão',
                manual: 'Manual',
                search_strategy: 'Estratégia de busca',
            };
            return map[source] || source || 'inference';
        },

        renderHiddenBanner(stats) {
            const el = document.getElementById('tech-sheet-hidden-banner');
            if (!el) return;
            const hidden = Number(stats?.hidden_gap_items ?? 0);
            if (!Number.isFinite(hidden) || hidden < 10) {
                el.classList.add('d-none');
                el.innerHTML = '';
                return;
            }
            el.classList.remove('d-none');
            el.innerHTML = `🔮 <strong>${hidden}</strong> itens com lacunas Hidden SEO — priorize LINE/MPN/HANDLE_RISER com evidência (origem: Hidden SEO).`;
        },

        closeDrawer() {
            document.getElementById('item-drawer').classList.remove('open');
            document.getElementById('drawer-backdrop').classList.remove('open');
            this.state.currentItem = null;
        },

        currentDrawerItemId() {
            const cur = this.state.currentItem;
            if (typeof cur === 'string' && cur.indexOf('MLB') === 0) {
                return cur;
            }
            if (cur && cur.item && cur.item.item_id) {
                return cur.item.item_id;
            }
            return null;
        },

        async drawerGenerate() {
            const itemId = this.currentDrawerItemId();
            if (!itemId) return;
            await this.quickGenerate(itemId);
            await this.openDrawer(itemId);
        },

        async drawerApply() {
            const itemId = this.currentDrawerItemId();
            if (!itemId) return;
            await this.quickApply(itemId);
            await this.openDrawer(itemId);
        },

        async decideSuggestion(itemId, attributeId, status) {
            try {
                const data = await requestJson(`/api/seo/technical-sheet/items/${encodeURIComponent(itemId)}/suggestions/decisions`, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json'
                    },
                    body: JSON.stringify({
                        decisions: [{
                            attribute_id: attributeId,
                            status: status
                        }]
                    })
                });

                if (data.success) {
                    // Refresh drawer
                    await this.openDrawer(itemId);
                } else {
                    alert(data.error || 'Erro ao salvar decisão');
                }
            } catch (e) {
                alert('Erro de conexão');
            }
        },

        async quickGenerate(itemId) {
            try {
                const data = await requestJson(`/api/seo/technical-sheet/items/${encodeURIComponent(itemId)}/suggestions/generate`, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json'
                    }
                });

                if (data.success) {
                    this.showToast(`✅ ${data.created || 0} sugestões geradas`);
                    this.loadList();
                } else {
                    alert(data.error || 'Erro ao gerar sugestões');
                }
            } catch (e) {
                alert('Erro de conexão');
            }
        },

        async quickApply(itemId) {
            if (!confirm('Aplicar todas as sugestões aprovadas no Mercado Livre?')) return;

            try {
                const data = await requestJson(`/api/seo/technical-sheet/items/${encodeURIComponent(itemId)}/apply`, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json'
                    }
                });

                if (data.success) {
                    this.showToast(`✅ ${data.applied || 0} atributos aplicados`);
                    this.loadList();
                } else {
                    alert(data.error || 'Erro ao aplicar');
                }
            } catch (e) {
                alert('Erro de conexão');
            }
        },

        async bulkGenerate() {
            const ids = Array.from(this.state.selected);
            if (!ids.length) return;

            if (!confirm(`Gerar sugestões para ${ids.length} itens?`)) return;

            this.showJobModal();

            try {
                const data = await requestJson('/api/seo/technical-sheet/batch/suggestions/generate', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json'
                    },
                    body: JSON.stringify({
                        item_ids: ids
                    })
                });

                if (data.success && data.job_id) {
                    this.pollJobStatus(data.job_id);
                } else {
                    this.hideJobModal();
                    alert(data.error || 'Erro ao iniciar job');
                }
            } catch (e) {
                this.hideJobModal();
                alert('Erro de conexão');
            }
        },

        async bulkApprove() {
            const ids = Array.from(this.state.selected);
            if (!ids.length) return;

            const minConfidence = parseInt(document.getElementById('bulk-confidence').value) || 85;

            if (!confirm(`Aprovar sugestões com confiança ≥ ${minConfidence}% para ${ids.length} itens?`)) return;

            this.showJobModal();

            try {
                const data = await requestJson('/api/seo/technical-sheet/batch/approve', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json'
                    },
                    body: JSON.stringify({
                        item_ids: ids,
                        min_confidence: minConfidence
                    })
                });

                if (data.success && data.job_id) {
                    this.pollJobStatus(data.job_id);
                } else {
                    this.hideJobModal();
                    alert(data.error || 'Erro ao iniciar job');
                }
            } catch (e) {
                this.hideJobModal();
                alert('Erro de conexão');
            }
        },

        async bulkApply() {
            const ids = Array.from(this.state.selected);
            if (!ids.length) return;

            if (!confirm(`Aplicar sugestões aprovadas no ML para ${ids.length} itens?`)) return;

            this.showJobModal();

            try {
                const data = await requestJson('/api/seo/technical-sheet/batch/apply', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json'
                    },
                    body: JSON.stringify({
                        item_ids: ids
                    })
                });

                if (data.success && data.job_id) {
                    this.pollJobStatus(data.job_id);
                } else {
                    this.hideJobModal();
                    alert(data.error || 'Erro ao iniciar job');
                }
            } catch (e) {
                this.hideJobModal();
                alert('Erro de conexão');
            }
        },

        showJobModal() {
            const modal = new bootstrap.Modal(document.getElementById('jobResultModal'));
            document.getElementById('job-progress').style.width = '0%';
            document.getElementById('job-progress').textContent = '0%';
            document.getElementById('job-status-text').textContent = 'Iniciando...';
            document.getElementById('job-spinner').style.display = 'block';
            document.getElementById('job-result-summary').style.display = 'none';
            document.getElementById('job-modal-footer').style.display = 'none';
            modal.show();
        },

        hideJobModal() {
            const modalEl = document.getElementById('jobResultModal');
            const modal = bootstrap.Modal.getInstance(modalEl);
            if (modal) modal.hide();
        },

        async pollJobStatus(jobId) {
            const maxAttempts = 60;
            let attempts = 0;

            const poll = async () => {
                attempts++;

                try {
                    const data = await requestJson(`/api/jobs/${jobId}`);

                    const status = data.status;
                    const result = data.result ? JSON.parse(data.result) : null;

                    if (status === 'completed' || status === 'failed') {
                        document.getElementById('job-spinner').style.display = 'none';
                        document.getElementById('job-progress').style.width = '100%';
                        document.getElementById('job-progress').textContent = '100%';
                        document.getElementById('job-progress').classList.remove('progress-bar-animated');

                        if (status === 'completed') {
                            document.getElementById('job-progress').classList.add('bg-success');
                            document.getElementById('job-status-text').textContent = '✅ Concluído!';

                            if (result) {
                                let summary = '<div class="alert alert-success">';
                                summary += `<strong>Processados:</strong> ${result.processed_items || 0}<br>`;
                                summary += `<strong>Sucesso:</strong> ${result.successful_items || 0}<br>`;
                                if (result.failed_items > 0) {
                                    summary += `<strong class="text-danger">Falhas:</strong> ${result.failed_items}`;
                                }
                                summary += '</div>';
                                document.getElementById('job-result-summary').innerHTML = summary;
                                document.getElementById('job-result-summary').style.display = 'block';
                            }
                        } else {
                            document.getElementById('job-progress').classList.add('bg-danger');
                            document.getElementById('job-status-text').textContent = '❌ Falhou: ' + (data.error_message || 'Erro desconhecido');
                        }

                        document.getElementById('job-modal-footer').style.display = 'flex';
                        this.clearSelection();
                        return;
                    }

                    // Still processing
                    const progress = Math.min(90, (attempts / maxAttempts) * 100);
                    document.getElementById('job-progress').style.width = progress + '%';
                    document.getElementById('job-progress').textContent = Math.round(progress) + '%';
                    document.getElementById('job-status-text').textContent = 'Processando... ' + (status === 'processing' ? '🔄' : '⏳');

                    if (attempts < maxAttempts) {
                        setTimeout(poll, 2000);
                    } else {
                        document.getElementById('job-status-text').textContent = '⚠️ Timeout - verifique o status manualmente';
                        document.getElementById('job-modal-footer').style.display = 'flex';
                    }
                } catch (e) {
                    console.error('Poll error:', e);
                    if (attempts < maxAttempts) {
                        setTimeout(poll, 3000);
                    }
                }
            };

            poll();
        },

        async syncItems() {
            if (!confirm('Sincronizar anúncios do Mercado Livre?\nIsso pode demorar alguns segundos.')) return;

            try {
                const data = await requestJson('/api/sync/trigger', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json'
                    },
                    body: JSON.stringify({
                        resource: 'items'
                    })
                });

                if (data.success) {
                    this.showToast('✅ Sincronização iniciada');
                    setTimeout(() => this.loadList(), 3000);
                } else {
                    alert(data.error || 'Erro ao sincronizar');
                }
            } catch (e) {
                alert('Erro de conexão');
            }
        },

        // ========== REFRESH ITEM FROM API ==========

        async refreshItem(itemId) {
            this.closeAllDropdowns();

            this.showToast(`🔄 Atualizando ${itemId}...`);

            try {
                const data = await requestJson(`/api/seo/technical-sheet/items/${encodeURIComponent(itemId)}/refresh`, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json'
                    }
                });

                if (data.success) {
                    const gaps = data.gaps?.missing || 0;
                    this.showToast(`✅ Atualizado! ${data.attributes_count} atributos, ${gaps} lacunas`);
                    this.loadList();
                    this.loadStats();

                    // If drawer is open for this item, refresh it
                    if (this.state.currentItem?.item?.item_id === itemId) {
                        await this.openDrawer(itemId);
                    }
                } else {
                    alert(data.error || 'Erro ao atualizar item');
                }
            } catch (e) {
                alert('Erro de conexão: ' + e.message);
            }
        },

        // ========== QUICK SUGGESTIONS (NO AI) ==========

        async quickSuggestions(itemId) {
            this.closeAllDropdowns();

            this.showToast(`⚡ Gerando sugestões rápidas para ${itemId}...`);

            try {
                const data = await requestJson(`/api/seo/technical-sheet/items/${encodeURIComponent(itemId)}/suggestions/quick`, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json'
                    }
                });

                if (data.success) {
                    const created = data.created || 0;
                    const extracted = data.extracted || 0;

                    if (created > 0) {
                        this.showToast(`✅ ${created} sugestões criadas de ${extracted} atributos extraídos!`);
                    } else if (extracted > 0) {
                        this.showToast(`ℹ️ ${extracted} atributos extraídos, mas nenhum era gap`);
                    } else {
                        this.showToast(`ℹ️ Nenhum atributo pôde ser extraído do título`);
                    }

                    this.loadList();
                    this.loadStats();

                    // If drawer is open for this item, refresh it
                    if (this.state.currentItem?.item?.item_id === itemId) {
                        await this.openDrawer(itemId);
                    }
                } else {
                    alert(data.error || 'Erro ao gerar sugestões');
                }
            } catch (e) {
                alert('Erro de conexão: ' + e.message);
            }
        },

        // ========== 🎯 SMART FILL GAPS (SEO MULTI-SOURCE) ==========

        async smartFillGaps(itemId) {
            this.closeAllDropdowns();

            this.showToast(`🎯 Analisando lacunas com múltiplas fontes SEO para ${itemId}...`);

            try {
                const data = await requestJson(`/api/seo/technical-sheet/items/${encodeURIComponent(itemId)}/smart-fill`, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json'
                    },
                    body: JSON.stringify({
                        sources: ['title', 'description', 'benchmark', 'autocomplete', 'trends'],
                        min_confidence: 50,
                        max_suggestions: 3
                    })
                });

                if (data.success) {
                    const saved = data.saved_count || 0;
                    const covered = data.gaps_covered || 0;
                    const analyzed = data.gaps_analyzed || 0;
                    const sources = Object.keys(data.sources_used || {}).join(', ') || 'nenhuma';

                    if (saved > 0) {
                        this.showToast(`✅ ${saved} sugestões criadas! (${covered}/${analyzed} gaps cobertos via: ${sources})`);
                    } else if (analyzed > 0) {
                        this.showToast(`ℹ️ Nenhuma sugestão encontrada para ${analyzed} lacunas. Tente IA.`);
                    } else {
                        this.showToast(`✅ Nenhuma lacuna encontrada - ficha técnica completa!`);
                    }

                    this.loadList();
                    this.loadStats();

                    if (this.state.currentItem?.item?.item_id === itemId) {
                        await this.openDrawer(itemId);
                    }
                } else {
                    alert(data.error || 'Erro ao preencher lacunas');
                }
            } catch (e) {
                alert('Erro de conexão: ' + e.message);
            }
        },

        async coverageAnalysis(itemId) {
            this.closeAllDropdowns();

            try {
                const data = await requestJson(`/api/seo/technical-sheet/items/${encodeURIComponent(itemId)}/coverage-analysis`);

                if (data.success) {
                    let msg = `📊 Análise de Cobertura:\n\n`;
                    msg += `Total de lacunas: ${data.total_gaps}\n`;
                    msg += `Cobertura combinada: ${data.combined_coverage.coverage_percent}%\n\n`;
                    msg += `Por fonte:\n`;

                    for (const [source, info] of Object.entries(data.coverage_by_source)) {
                        msg += `  • ${source}: ${info.coverage_percent}% (${info.gaps_covered} gaps)\n`;
                    }

                    msg += `\n${data.recommendation}`;

                    alert(msg);
                } else {
                    alert(data.error || 'Erro na análise');
                }
            } catch (e) {
                alert('Erro de conexão: ' + e.message);
            }
        },

        async bulkSmartFill() {
            const ids = Array.from(this.state.selected);
            if (!ids.length) {
                alert('Selecione ao menos um item');
                return;
            }

            if (!confirm(`🎯 Preencher lacunas com Smart Fill (múltiplas fontes SEO) para ${ids.length} item(ns)?`)) return;

            this.showProgressModal('🎯 Smart Fill SEO', ids.length);

            let success = 0;
            let failed = 0;
            let totalSaved = 0;

            for (let i = 0; i < ids.length; i++) {
                const itemId = ids[i];
                this.updateProgress(i, ids.length, `Processando item ${i+1}/${ids.length}...`);

                try {
                    const data = await requestJson(`/api/seo/technical-sheet/items/${encodeURIComponent(itemId)}/smart-fill`, {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json'
                        },
                        body: JSON.stringify({
                            sources: ['title', 'description', 'benchmark', 'autocomplete'],
                            min_confidence: 60
                        })
                    });
                    if (data.success) {
                        success++;
                        totalSaved += data.saved_count || 0;
                    } else {
                        failed++;
                    }
                } catch (e) {
                    failed++;
                }
            }

            this.finishProgress(`✅ <strong>${success}</strong> processados, <strong>${totalSaved}</strong> sugestões SEO criadas`, failed);
            this.clearSelection();
            this.loadList();
            this.loadStats();
        },

        async bulkRefresh() {
            const ids = Array.from(this.state.selected);
            if (!ids.length) {
                alert('Selecione ao menos um item');
                return;
            }

            if (!confirm(`Atualizar ${ids.length} item(ns) da API do Mercado Livre?`)) return;

            this.showToast(`🔄 Atualizando ${ids.length} itens...`);

            let success = 0;
            let failed = 0;

            for (const itemId of ids) {
                try {
                    const data = await requestJson(`/api/seo/technical-sheet/items/${encodeURIComponent(itemId)}/refresh`, {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json'
                        }
                    });
                    if (data.success) success++;
                    else failed++;
                } catch (e) {
                    failed++;
                }
            }

            this.showToast(`✅ ${success} atualizados, ${failed} falhas`);
            this.clearSelection();
            this.loadList();
            this.loadStats();
        },

        async bulkQuickSuggestions() {
            const ids = Array.from(this.state.selected);
            if (!ids.length) {
                alert('Selecione ao menos um item');
                return;
            }

            if (!confirm(`Gerar sugestões rápidas (extração do título) para ${ids.length} item(ns)?`)) return;

            // Show progress modal
            this.showProgressModal('⚡ Sugestões Rápidas', ids.length);

            let success = 0;
            let failed = 0;
            let totalCreated = 0;

            for (let i = 0; i < ids.length; i++) {
                const itemId = ids[i];
                this.updateProgress(i, ids.length, `Processando item ${i+1}/${ids.length}...`);

                try {
                    const data = await requestJson(`/api/seo/technical-sheet/items/${encodeURIComponent(itemId)}/suggestions/quick`, {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json'
                        }
                    });
                    if (data.success) {
                        success++;
                        totalCreated += data.created || 0;
                    } else {
                        failed++;
                    }
                } catch (e) {
                    failed++;
                }
            }

            this.finishProgress(`✅ <strong>${success}</strong> processados, <strong>${totalCreated}</strong> sugestões criadas`, failed);
            this.clearSelection();
            this.loadList();
            this.loadStats();
        },

        // ========== MODEL SUGGESTIONS (SEARCH STRATEGIES) ==========

        async modelSuggestions(itemId) {
            this.closeAllDropdowns();

            this.showToast(`🔍 Gerando sugestões de MODELO para ${itemId}...`);

            try {
                const data = await requestJson(`/api/seo/technical-sheet/items/${encodeURIComponent(itemId)}/suggestions/model`, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json'
                    }
                });

                if (data.success) {
                    const created = data.suggestions_created || 0;
                    const found = data.suggestions_found || 0;
                    const strategies = Object.keys(data.strategies_used || {}).join(', ') || 'título';

                    if (created > 0) {
                        this.showToast(`✅ ${created} sugestões de MODELO criadas! (via: ${strategies})`);
                    } else if (found > 0) {
                        this.showToast(`ℹ️ ${found} modelos encontrados, mas já existem sugestões`);
                    } else if (data.message) {
                        this.showToast(`ℹ️ ${data.message}`);
                    } else {
                        this.showToast(`ℹ️ Nenhum modelo encontrado para este item`);
                    }

                    this.loadList();
                    this.loadStats();

                    if (this.state.currentItem?.item?.item_id === itemId) {
                        await this.openDrawer(itemId);
                    }
                } else {
                    alert(data.error || 'Erro ao gerar sugestões de modelo');
                }
            } catch (e) {
                alert('Erro de conexão: ' + e.message);
            }
        },

        async bulkModelSuggestions() {
            const ids = Array.from(this.state.selected);
            if (!ids.length) {
                alert('Selecione ao menos um item');
                return;
            }

            if (!confirm(`Gerar sugestões de MODELO (estratégias de busca) para ${ids.length} item(ns)?\n\nIsso irá analisar títulos e itens relacionados para sugerir modelos de veículos.`)) return;

            // Show progress modal
            this.showProgressModal('🔍 Sugestões de MODELO', ids.length);

            let success = 0;
            let failed = 0;
            let totalCreated = 0;

            for (let i = 0; i < ids.length; i++) {
                const itemId = ids[i];
                this.updateProgress(i, ids.length, `Analisando item ${i+1}/${ids.length}...`);

                try {
                    const data = await requestJson(`/api/seo/technical-sheet/items/${encodeURIComponent(itemId)}/suggestions/model`, {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json'
                        }
                    });
                    if (data.success) {
                        success++;
                        totalCreated += data.suggestions_created || 0;
                    } else {
                        failed++;
                    }
                } catch (e) {
                    failed++;
                }
            }

            this.finishProgress(`✅ <strong>${success}</strong> analisados, <strong>${totalCreated}</strong> sugestões de MODELO criadas`, failed);
            this.clearSelection();
            this.loadList();
            this.loadStats();
        },

        // ========== KEYWORD MINING ==========

        minedKeywords: [], // Cache das keywords mineradas

        async mineKeywords(itemId, categoryId) {
            this.closeAllDropdowns();

            const modal = new bootstrap.Modal(document.getElementById('keywordsModal'));
            modal.show();

            document.getElementById('keywords-loading').style.display = 'block';
            document.getElementById('keywords-results').style.display = 'none';

            try {
                // Buscar keywords da categoria
                const data = await requestJson(`/api/seo/keywords/mine/${encodeURIComponent(categoryId)}?term=`);

                if (data.success && data.data) {
                    const combined = data.data.combined || [];
                    const attrKw = data.data.attribute_keywords || [];
                    const catKw = data.data.category_keywords || [];

                    this.minedKeywords = combined;

                    // Atualizar KPIs
                    const values = combined.filter(k => k.type === 'attribute_value').length;
                    const names = combined.filter(k => k.type === 'attribute_name').length;

                    document.getElementById('kw-total').textContent = combined.length;
                    document.getElementById('kw-values').textContent = values;
                    document.getElementById('kw-names').textContent = names;

                    // Renderizar lista
                    this.renderKeywordsList(combined);

                    document.getElementById('keywords-loading').style.display = 'none';
                    document.getElementById('keywords-results').style.display = 'block';
                } else {
                    throw new Error(data.error || 'Erro ao minerar keywords');
                }
            } catch (e) {
                document.getElementById('keywords-loading').innerHTML = `
                <div class="text-danger">
                    <i class="fas fa-exclamation-circle fa-2x mb-2"></i>
                    <p>${e.message}</p>
                </div>
            `;
            }
        },

        renderKeywordsList(keywords) {
            const container = document.getElementById('keywords-list');

            if (!keywords.length) {
                container.innerHTML = '<p class="text-muted">Nenhuma keyword encontrada.</p>';
                return;
            }

            // Agrupar por tipo
            const byType = {};
            keywords.forEach(kw => {
                const type = kw.type || 'other';
                if (!byType[type]) byType[type] = [];
                byType[type].push(kw);
            });

            const typeLabels = {
                'attribute_value': '📊 Valores de Atributos',
                'attribute_name': '🏷️ Nomes de Atributos',
                'category_name': '📁 Categorias',
                'subcategory': '📂 Subcategorias',
                'parent_category': '🗂️ Categorias Pai',
                'domain_discovery': '🔍 Descoberta de Domínio'
            };

            const typeColors = {
                'attribute_value': 'success',
                'attribute_name': 'secondary',
                'category_name': 'info',
                'subcategory': 'primary',
                'parent_category': 'warning',
                'domain_discovery': 'danger'
            };

            let html = '';
            for (const [type, kws] of Object.entries(byType)) {
                const label = typeLabels[type] || type;
                const color = typeColors[type] || 'secondary';

                html += `
                <div class="mb-3">
                    <h6 class="text-${color} mb-2">${label} (${kws.length})</h6>
                    <div class="d-flex flex-wrap gap-1">
                        ${kws.slice(0, 30).map(kw => `
                            <span class="badge bg-${color} bg-opacity-10 text-${color} fw-normal keyword-badge"
                                  data-keyword="${this.escapeHtml(kw.keyword)}"
                                  onclick="TechSheet.copyKeyword('${this.escapeHtml(kw.keyword)}')"
                                  style="cursor: pointer;" title="Clique para copiar">
                                ${this.escapeHtml(kw.keyword)}
                            </span>
                        `).join('')}
                        ${kws.length > 30 ? `<span class="badge bg-light text-muted">+${kws.length - 30} mais</span>` : ''}
                    </div>
                </div>
            `;
            }

            container.innerHTML = html;
        },

        filterKeywords(query) {
            const q = query.toLowerCase().trim();
            if (!q) {
                this.renderKeywordsList(this.minedKeywords);
                return;
            }

            const filtered = this.minedKeywords.filter(kw =>
                kw.keyword.toLowerCase().includes(q)
            );
            this.renderKeywordsList(filtered);
        },

        copyKeyword(keyword) {
            navigator.clipboard.writeText(keyword).then(() => {
                this.showToast(`📋 Copiado: ${keyword}`);
            });
        },

        copyKeywords() {
            const keywords = this.minedKeywords.map(k => k.keyword).join('\n');
            navigator.clipboard.writeText(keywords).then(() => {
                this.showToast(`📋 ${this.minedKeywords.length} keywords copiadas!`);
            });
        },

        escapeHtml(text) {
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        },

        async openMotoKeywordsModal() {
            const modal = new bootstrap.Modal(document.getElementById('keywordsModal'));
            modal.show();

            document.getElementById('keywords-loading').style.display = 'block';
            document.getElementById('keywords-loading').innerHTML = `
            <div class="spinner-border text-info"></div>
            <p class="mt-2 text-muted">Minerando keywords de todas as categorias de motos...</p>
            <small class="text-muted">Isso pode levar alguns segundos.</small>
        `;
            document.getElementById('keywords-results').style.display = 'none';

            try {
                const data = await requestJson('/api/seo/keywords/mine-moto');

                if (data.success && data.data) {
                    const allKw = data.data.all_keywords || [];
                    const stats = data.statistics || {};

                    this.minedKeywords = allKw;

                    // Atualizar KPIs
                    const values = allKw.filter(k => k.type === 'attribute_value').length;
                    const names = allKw.filter(k => k.type === 'attribute_name').length;

                    document.getElementById('kw-total').textContent = stats.total_keywords || allKw.length;
                    document.getElementById('kw-values').textContent = values;
                    document.getElementById('kw-names').textContent = names;

                    // Renderizar lista
                    this.renderKeywordsList(allKw);

                    document.getElementById('keywords-loading').style.display = 'none';
                    document.getElementById('keywords-results').style.display = 'block';

                    this.showToast(`✅ ${stats.total_keywords} keywords de ${stats.total_categories} categorias`);
                } else {
                    throw new Error(data.error || 'Erro ao minerar keywords');
                }
            } catch (e) {
                document.getElementById('keywords-loading').innerHTML = `
                <div class="text-danger">
                    <i class="fas fa-exclamation-circle fa-2x mb-2"></i>
                    <p>${e.message}</p>
                </div>
            `;
            }
        },

        // ========== MARKET ANALYSIS ==========

        currentMarketCategory: null,

        async openMarketAnalysis(categoryId = null) {
            // Se não foi passada categoria, usar a selecionada no filtro ou a primeira da lista
            if (!categoryId) {
                categoryId = document.getElementById('filter-category')?.value;
                if (!categoryId) {
                    // Pegar categoria do primeiro item da lista
                    const firstItem = document.querySelector('#items-tbody tr');
                    if (firstItem) {
                        categoryId = firstItem.dataset.categoryId;
                    }
                }
            }

            if (!categoryId) {
                this.showToast('⚠️ Selecione uma categoria no filtro primeiro', 'warning');
                return;
            }

            this.currentMarketCategory = categoryId;

            const modal = new bootstrap.Modal(document.getElementById('marketAnalysisModal'));
            modal.show();

            document.getElementById('market-loading').style.display = 'block';
            document.getElementById('market-results').style.display = 'none';

            await this.loadMarketData(categoryId);
        },

        async loadMarketData(categoryId) {
            try {
                // Buscar análise de mercado
                const [categoryData, pricingData, domainsData] = await Promise.all([
                    requestJson(`/api/market/category/${categoryId}`),
                    requestJson(`/api/market/pricing/${categoryId}`),
                    requestJson(`/api/market/discover?q=${encodeURIComponent(categoryId.replace('MLB', ''))}`)
                ]);

                // Atualizar informações da categoria
                if (categoryData.success && categoryData.data) {
                    const cat = categoryData.data;
                    document.getElementById('market-category-name').textContent = cat.name || categoryId;
                    document.getElementById('market-category-path').textContent =
                        (cat.path_from_root || []).map(p => p.name).join(' > ') || '-';
                    document.getElementById('market-total-items').textContent =
                        (cat.total_items_in_this_category || 0).toLocaleString('pt-BR');
                }

                // Atualizar preços
                if (pricingData.success && pricingData.data?.price_stats) {
                    const stats = pricingData.data.price_stats;
                    const features = pricingData.data.market_features || {};

                    document.getElementById('market-price-min').textContent =
                        stats.min ? `R$ ${stats.min.toFixed(2)}` : '-';
                    document.getElementById('market-price-median').textContent =
                        stats.median ? `R$ ${stats.median.toFixed(2)}` : '-';
                    document.getElementById('market-price-avg').textContent =
                        stats.avg ? `R$ ${stats.avg.toFixed(2)}` : '-';
                    document.getElementById('market-price-max').textContent =
                        stats.max ? `R$ ${stats.max.toFixed(2)}` : '-';

                    // Features do mercado
                    document.getElementById('market-free-shipping').textContent =
                        (features.free_shipping_percent || 0) + '%';
                    document.getElementById('market-full').textContent =
                        (features.full_percent || 0) + '%';
                    document.getElementById('market-official').textContent =
                        (features.official_stores_percent || 0) + '%';
                    document.getElementById('market-catalog').textContent =
                        (features.catalog_percent || 0) + '%';

                    // Recomendações
                    const recs = pricingData.data.recommendations || {};
                    let recsHtml = '';

                    if (recs.competitive_price) {
                        recsHtml += `
                        <div class="alert alert-success py-2 mb-2">
                            <strong>💰 Preço Competitivo:</strong> R$ ${recs.competitive_price.value}
                            <small class="d-block text-muted">${recs.competitive_price.description}</small>
                        </div>
                    `;
                    }

                    if (recs.premium_price) {
                        recsHtml += `
                        <div class="alert alert-warning py-2 mb-2">
                            <strong>⭐ Preço Premium:</strong> R$ ${recs.premium_price.value}
                            <small class="d-block text-muted">${recs.premium_price.description}</small>
                        </div>
                    `;
                    }

                    if (recs.shipping) {
                        const shippingClass = recs.shipping.action === 'REQUIRED' ? 'danger' : 'info';
                        recsHtml += `
                        <div class="alert alert-${shippingClass} py-2 mb-2">
                            <strong>🚚 Frete:</strong> ${recs.shipping.action}
                            <small class="d-block text-muted">${recs.shipping.description}</small>
                        </div>
                    `;
                    }

                    if (recs.fulfillment) {
                        recsHtml += `
                        <div class="alert alert-primary py-2 mb-2">
                            <strong>📦 Full:</strong> ${recs.fulfillment.action}
                            <small class="d-block text-muted">${recs.fulfillment.description}</small>
                        </div>
                    `;
                    }

                    document.getElementById('market-recommendations').innerHTML = recsHtml ||
                        '<p class="text-muted small">Nenhuma recomendação específica.</p>';

                    // Metadata
                    document.getElementById('market-source').textContent = pricingData.data.source || 'API';
                    document.getElementById('market-sample').textContent = pricingData.data.sample_size || 0;
                } else {
                    document.getElementById('market-recommendations').innerHTML =
                        '<p class="text-warning">Dados de preço não disponíveis para esta categoria.</p>';
                }

                // Domínios relacionados
                if (domainsData.success && domainsData.domains?.length) {
                    let domainsHtml = '<div class="d-flex flex-wrap gap-2">';
                    domainsData.domains.forEach(d => {
                        domainsHtml += `
                        <span class="badge bg-light text-dark border" style="cursor: pointer;"
                              onclick="TechSheet.openMarketAnalysis('${d.category_id}')"
                              title="${d.domain_name}">
                            ${d.category_name}
                            <small class="text-muted">(${d.category_id})</small>
                        </span>
                    `;
                    });
                    domainsHtml += '</div>';
                    document.getElementById('market-domains').innerHTML = domainsHtml;
                } else {
                    document.getElementById('market-domains').innerHTML =
                        '<p class="text-muted small">Nenhuma categoria relacionada encontrada.</p>';
                }

                document.getElementById('market-loading').style.display = 'none';
                document.getElementById('market-results').style.display = 'block';

            } catch (e) {
                document.getElementById('market-loading').innerHTML = `
                <div class="text-danger text-center">
                    <i class="fas fa-exclamation-circle fa-2x mb-2"></i>
                    <p>${e.message || 'Erro ao carregar análise de mercado'}</p>
                    <button class="btn btn-sm btn-outline-primary" onclick="TechSheet.loadMarketData('${categoryId}')">
                        <i class="fas fa-redo me-1"></i> Tentar novamente
                    </button>
                </div>
            `;
            }
        },

        refreshMarketAnalysis() {
            if (this.currentMarketCategory) {
                document.getElementById('market-loading').style.display = 'block';
                document.getElementById('market-results').style.display = 'none';
                this.loadMarketData(this.currentMarketCategory);
            }
        },

        // ========== QUALITY ANALYSIS ==========

        async analyzeQuality(itemId) {
            // Mostrar loading toast
            this.showToast('⏳ Analisando qualidade do anúncio...', 'info');

            try {
                const data = await requestJson(`/api/market/quality/${itemId}`);

                if (data.success) {
                    this.showQualityResults(itemId, data);
                } else {
                    throw new Error(data.error || 'Erro ao analisar qualidade');
                }
            } catch (e) {
                this.showToast(`❌ ${e.message}`, 'danger');
            }
        },

        showQualityResults(itemId, data) {
            const scores = data.scores || {};
            const overall = data.overall_score || 0;
            const issues = data.issues || [];
            const recommendations = data.recommendations || [];

            // Determinar classe do score overall
            const overallClass = overall >= 80 ? 'success' : overall >= 60 ? 'warning' : 'danger';

            let scoreCardsHtml = '';
            const scoreCategories = [{
                    key: 'title',
                    icon: 'heading',
                    label: 'Título'
                },
                {
                    key: 'description',
                    icon: 'align-left',
                    label: 'Descrição'
                },
                {
                    key: 'images',
                    icon: 'images',
                    label: 'Imagens'
                },
                {
                    key: 'attributes',
                    icon: 'list-check',
                    label: 'Atributos'
                },
                {
                    key: 'shipping',
                    icon: 'truck',
                    label: 'Frete'
                },
                {
                    key: 'pricing',
                    icon: 'tag',
                    label: 'Preço'
                }
            ];

            scoreCategories.forEach(cat => {
                const score = scores[cat.key] || 0;
                const scoreClass = score >= 80 ? 'success' : score >= 60 ? 'warning' : 'danger';
                scoreCardsHtml += `
                <div class="col-4 col-md-2">
                    <div class="text-center p-2 border rounded">
                        <i class="fas fa-${cat.icon} text-${scoreClass} mb-1"></i>
                        <div class="fw-bold fs-5 text-${scoreClass}">${score}</div>
                        <small class="text-muted">${cat.label}</small>
                    </div>
                </div>
            `;
            });

            let issuesHtml = '';
            if (issues.length > 0) {
                issues.forEach(issue => {
                    const severity = issue.severity || 'warning';
                    const icon = severity === 'critical' ? 'exclamation-circle' : 'exclamation-triangle';
                    issuesHtml += `
                    <div class="alert alert-${severity === 'critical' ? 'danger' : 'warning'} py-2 mb-2 small">
                        <i class="fas fa-${icon} me-1"></i> ${issue.message}
                    </div>
                `;
                });
            } else {
                issuesHtml = '<p class="text-success small"><i class="fas fa-check-circle me-1"></i> Nenhum problema encontrado!</p>';
            }

            let recsHtml = '';
            if (recommendations.length > 0) {
                recommendations.forEach(rec => {
                    recsHtml += `
                    <li class="small">${rec}</li>
                `;
                });
                recsHtml = `<ul class="ps-3 mb-0">${recsHtml}</ul>`;
            } else {
                recsHtml = '<p class="text-muted small">Seu anúncio está ótimo!</p>';
            }

            const modalHtml = `
            <div class="modal fade" id="qualityModal" tabindex="-1">
                <div class="modal-dialog modal-lg">
                    <div class="modal-content">
                        <div class="modal-header bg-gradient-primary text-white">
                            <h5 class="modal-title">
                                <i class="fas fa-star me-2"></i>Qualidade do Anúncio
                            </h5>
                            <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                        </div>
                        <div class="modal-body">
                            <!-- Overall Score -->
                            <div class="text-center mb-4">
                                <div class="d-inline-block p-4 rounded-circle bg-${overallClass}" style="width: 120px; height: 120px;">
                                    <div class="display-4 fw-bold text-white">${overall}</div>
                                </div>
                                <div class="mt-2 fw-bold">Score Geral</div>
                                <small class="text-muted">${itemId}</small>
                            </div>

                            <!-- Score Categories -->
                            <div class="row g-2 mb-4">
                                ${scoreCardsHtml}
                            </div>

                            <!-- Issues -->
                            <h6 class="fw-bold"><i class="fas fa-bug text-danger me-2"></i>Problemas</h6>
                            <div class="mb-4">${issuesHtml}</div>

                            <!-- Recommendations -->
                            <h6 class="fw-bold"><i class="fas fa-lightbulb text-warning me-2"></i>Recomendações</h6>
                            ${recsHtml}
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-outline-primary" onclick="TechSheet.analyzeQuality('${itemId}')">
                                <i class="fas fa-sync me-1"></i> Reanalisar
                            </button>
                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Fechar</button>
                        </div>
                    </div>
                </div>
            </div>
        `;

            // Remover modal anterior se existir
            const existingModal = document.getElementById('qualityModal');
            if (existingModal) {
                existingModal.remove();
            }

            document.body.insertAdjacentHTML('beforeend', modalHtml);
            const modal = new bootstrap.Modal(document.getElementById('qualityModal'));
            modal.show();

            this.showToast(`✅ Score: ${overall}/100`, 'success');
        },

        // ========== PROGRESS MODAL HELPERS ==========

        showProgressModal(title, total) {
            const modal = document.getElementById('jobResultModal');
            modal.querySelector('.modal-title').textContent = title;
            document.getElementById('job-spinner').style.display = 'block';
            document.getElementById('job-progress').style.width = '0%';
            document.getElementById('job-progress').textContent = '0%';
            document.getElementById('job-status-text').textContent = `Iniciando... (0/${total})`;
            document.getElementById('job-result-summary').style.display = 'none';
            document.getElementById('job-modal-footer').style.display = 'none';

            this.progressModal = new bootstrap.Modal(modal);
            this.progressModal.show();
        },

        updateProgress(current, total, message) {
            const percent = Math.round((current / total) * 100);
            document.getElementById('job-progress').style.width = `${percent}%`;
            document.getElementById('job-progress').textContent = `${percent}%`;
            document.getElementById('job-status-text').textContent = message || `${current}/${total}`;
        },

        finishProgress(message, failed = 0) {
            document.getElementById('job-spinner').style.display = 'none';
            document.getElementById('job-progress').style.width = '100%';
            document.getElementById('job-progress').textContent = '100%';
            document.getElementById('job-progress').classList.remove('progress-bar-animated');

            let html = `<div class="text-center">${message}</div>`;
            if (failed > 0) {
                html += `<div class="text-center text-danger mt-2"><small>${failed} falha(s)</small></div>`;
            }

            document.getElementById('job-result-summary').innerHTML = html;
            document.getElementById('job-result-summary').style.display = 'block';
            document.getElementById('job-status-text').textContent = 'Concluído!';
            document.getElementById('job-modal-footer').style.display = 'flex';
        },

        showToast(message) {
            // Simple toast implementation
            const toast = document.createElement('div');
            toast.className = 'position-fixed bottom-0 end-0 p-3';
            toast.style.zIndex = '1100';
            toast.innerHTML = `
            <div class="toast show" role="alert">
                <div class="toast-body">${message}</div>
            </div>
        `;
            document.body.appendChild(toast);
            setTimeout(() => toast.remove(), 3000);
        },

        showError(message) {
            const tbody = document.getElementById('items-tbody');
            tbody.innerHTML = `
            <tr>
                <td colspan="6" class="text-center text-danger py-4">
                    <i class="fas fa-exclamation-triangle"></i> ${this.escapeHtml(message)}
                </td>
            </tr>
        `;
        },

        // Calcula SEO Score baseado em completude e lacunas
        calculateSeoScore(completeness, missingReq, missingFilter, missingHidden) {
            let score = completeness * 0.5; // Completude vale 50%

            // Penalidades por lacunas
            score -= missingReq * 15; // Cada obrigatório faltando = -15
            score -= missingFilter * 5; // Cada filtro faltando = -5
            score -= missingHidden * 3; // Cada hidden faltando = -3

            // Bônus se tudo estiver completo
            if (missingReq === 0 && missingFilter === 0 && missingHidden === 0) {
                score += 20;
            }

            return Math.max(0, Math.min(100, Math.round(score)));
        },

        // ========== DROPDOWN ACTIONS ==========

        toggleDropdown(event, itemId) {
            event.stopPropagation();

            // Close all other dropdowns
            document.querySelectorAll('.action-dropdown-menu.show').forEach(menu => {
                if (menu.id !== `dropdown-${itemId}`) {
                    menu.classList.remove('show');
                }
            });

            const menu = document.getElementById(`dropdown-${itemId}`);
            menu.classList.toggle('show');
        },

        // ========== EXTRACT FROM TITLE ==========

        async openExtractModal(itemId) {
            this.closeAllDropdowns();

            const modal = new bootstrap.Modal(document.getElementById('extractModal'));
            modal.show();

            document.getElementById('extract-loading').style.display = 'block';
            document.getElementById('extract-results').style.display = 'none';
            document.getElementById('extract-empty').style.display = 'none';
            document.getElementById('btn-apply-extract').disabled = true;

            try {
                // Get item data
                const data = await requestJson(`/api/seo/technical-sheet/items/${encodeURIComponent(itemId)}`);

                if (!data.success) throw new Error(data.error);

                const title = data.item.title || '';
                document.getElementById('extract-title').value = title;
                this.state.currentItem = data;

                // Extract attributes from title
                const extractData = await requestJson(`/api/seo/technical-sheet/items/${encodeURIComponent(itemId)}/extract-from-title`, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json'
                    },
                    body: JSON.stringify({
                        title,
                        category_id: data.item.category_id
                    })
                });

                document.getElementById('extract-loading').style.display = 'none';

                if (extractData.success && extractData.extracted_attributes?.length > 0) {
                    this.state.extractedAttributes = extractData.extracted_attributes.map(attr => ({
                        id: attr.attribute_id,
                        name: attr.name,
                        value: attr.value,
                        confidence: attr.confidence,
                        is_new: true
                    }));
                    this.renderExtractedAttributes(this.state.extractedAttributes);
                    document.getElementById('extract-results').style.display = 'block';
                    document.getElementById('btn-apply-extract').disabled = false;
                } else {
                    document.getElementById('extract-empty').style.display = 'block';
                }

            } catch (e) {
                document.getElementById('extract-loading').style.display = 'none';
                document.getElementById('extract-empty').style.display = 'block';
                console.error('Extract error:', e);
            }
        },

        renderExtractedAttributes(attributes) {
            const list = document.getElementById('extract-list');
            list.innerHTML = attributes.map(attr => `
            <div class="extract-item ${attr.is_new ? 'new' : ''}">
                <div>
                    <div class="fw-bold">${this.escapeHtml(attr.name)}</div>
                    <small class="text-muted">ID: ${attr.id}</small>
                </div>
                <div class="text-end">
                    <div class="fw-semibold text-success">${this.escapeHtml(attr.value)}</div>
                    <small class="text-muted">${attr.confidence}% confiança</small>
                </div>
            </div>
        `).join('');
        },

        async applyExtracted() {
            if (!this.state.extractedAttributes?.length || !this.state.currentItem?.item?.item_id) return;

            const itemId = this.state.currentItem.item.item_id;

            try {
                const data = await requestJson(`/api/seo/technical-sheet/items/${encodeURIComponent(itemId)}/suggestions`, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json'
                    },
                    body: JSON.stringify({
                        suggestions: this.state.extractedAttributes.map(attr => ({
                            attribute_id: attr.id,
                            attribute_name: attr.name,
                            suggested_value: attr.value,
                            confidence: attr.confidence,
                            source: 'title_extraction'
                        }))
                    })
                });

                bootstrap.Modal.getInstance(document.getElementById('extractModal')).hide();

                if (data.success) {
                    this.showToast(`✅ ${data.added || 0} sugestão(ões) adicionada(s)! Revise e aprove no painel.`);
                    this.loadList();
                } else {
                    this.showToast('❌ Erro: ' + (data.error || 'Falha ao adicionar sugestões'));
                }
            } catch (e) {
                this.showToast('❌ Erro: ' + e.message);
            }
        },

        // ========== COMPARE WITH COMPETITORS ==========

        async openCompareModal(itemId) {
            this.closeAllDropdowns();

            const modal = new bootstrap.Modal(document.getElementById('compareModal'));
            modal.show();

            document.getElementById('compare-loading').style.display = 'block';
            document.getElementById('compare-results').style.display = 'none';
            document.getElementById('btn-fill-from-competitors').style.display = 'none';

            try {
                const data = await requestJson(`/api/seo/technical-sheet/items/${encodeURIComponent(itemId)}/compare-competitors`);

                document.getElementById('compare-loading').style.display = 'none';

                if (data.success) {
                    this.state.compareData = data;
                    this.state.currentItem = {
                        item: {
                            item_id: itemId
                        }
                    };
                    this.renderCompareResults(data);
                    document.getElementById('compare-results').style.display = 'block';

                    if (data.missing_attributes?.length > 0) {
                        document.getElementById('btn-fill-from-competitors').style.display = 'inline-block';
                    }
                } else {
                    alert('Erro ao comparar: ' + (data.error || 'Falha na comparação'));
                    bootstrap.Modal.getInstance(document.getElementById('compareModal')).hide();
                }

            } catch (e) {
                document.getElementById('compare-loading').style.display = 'none';
                alert('Erro: ' + e.message);
            }
        },

        renderCompareResults(data) {
            const totalCompetitors = data.competitors_analyzed || 0;
            const gaps = data.attribute_gaps || [];

            // KPIs
            document.getElementById('compare-your-score').textContent = totalCompetitors;
            document.getElementById('compare-avg-score').textContent = gaps.length;
            document.getElementById('compare-position').textContent = gaps.length === 0 ? '✓' : gaps.length;

            const posCard = document.getElementById('compare-position-card');
            posCard.className = 'kpi-card ' + (gaps.length === 0 ? 'success' : gaps.length <= 3 ? 'warning' : 'danger');

            // Missing attributes (gaps) com porcentagem de uso
            const missingList = document.getElementById('compare-missing-list');
            if (gaps.length > 0) {
                missingList.innerHTML = gaps.map(attr => {
                    const usageText = attr.usage_percent ? `${attr.usage_percent}%` : `${attr.competitor_usage}/${totalCompetitors}`;
                    const priorityClass = attr.priority === 'high' ? 'danger' : (attr.priority === 'medium' ? 'warning' : 'secondary');
                    return `
                <div class="d-flex justify-content-between align-items-center p-2 bg-light rounded mb-2">
                    <div>
                        <span class="fw-bold">${this.escapeHtml(attr.name)}</span>
                        <span class="badge bg-${priorityClass} ms-2">${usageText} usam</span>
                    </div>
                    <div class="text-end">
                        <small class="text-muted">Sugestão: </small>
                        <span class="badge bg-info">${this.escapeHtml(attr.common_values?.[0] || 'Variado')}</span>
                    </div>
                </div>
            `
                }).join('');

                // Store for fill action - com valores para sugestão
                this.state.compareData = {
                    ...data,
                    missing_attributes: gaps.map(g => ({
                        id: g.attribute_id,
                        name: g.name,
                        common_value: g.common_values?.[0] || null,
                        competitor_percentage: g.usage_percent || (g.competitor_usage / totalCompetitors * 100)
                    }))
                };
                document.getElementById('btn-fill-from-competitors').style.display = 'inline-block';
            } else {
                missingList.innerHTML = '<p class="text-success"><i class="fas fa-check-circle me-1"></i> Você tem todos os atributos dos concorrentes!</p>';
                document.getElementById('btn-fill-from-competitors').style.display = 'none';
            }

            // Top competitors com mais detalhes
            const commonList = document.getElementById('compare-common-list');
            if (data.competitors?.length > 0) {
                commonList.innerHTML = data.competitors.slice(0, 5).map(comp => `
                <div class="d-flex justify-content-between align-items-center p-2 border-bottom">
                    <small class="text-truncate" style="max-width: 180px" title="${this.escapeHtml(comp.title)}">${this.escapeHtml(comp.title)}</small>
                    <div>
                        <span class="badge bg-success me-1">R$ ${comp.price?.toLocaleString('pt-BR') || '0'}</span>
                        <span class="badge bg-info">${comp.sold_quantity || 0} vendas</span>
                    </div>
                </div>
            `).join('');
            } else {
                commonList.innerHTML = '<p class="text-muted">Nenhum concorrente analisado.</p>';
            }
        },

        async fillFromCompetitors() {
            if (!this.state.compareData?.missing_attributes || !this.state.currentItem?.item?.item_id) return;

            const itemId = this.state.currentItem.item.item_id;
            const suggestions = this.state.compareData.missing_attributes
                .filter(attr => attr.common_value)
                .map(attr => ({
                    attribute_id: attr.id,
                    attribute_name: attr.name,
                    suggested_value: attr.common_value,
                    confidence: Math.min(95, Math.round(attr.competitor_percentage)),
                    source: 'competitor_analysis'
                }));

            if (suggestions.length === 0) {
                this.showToast('⚠️ Não há valores sugeridos dos concorrentes.');
                return;
            }

            try {
                const data = await requestJson(`/api/seo/technical-sheet/items/${encodeURIComponent(itemId)}/suggestions`, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json'
                    },
                    body: JSON.stringify({
                        suggestions
                    })
                });

                bootstrap.Modal.getInstance(document.getElementById('compareModal')).hide();

                if (data.success) {
                    this.showToast(`✅ ${data.added || suggestions.length} sugestão(ões) adicionada(s) com base nos concorrentes!`);
                    this.loadList();
                } else {
                    this.showToast('❌ Erro: ' + (data.error || 'Falha ao adicionar'));
                }
            } catch (e) {
                this.showToast('❌ Erro: ' + e.message);
            }
        },

        // ========== EXPORT REPORT ==========

        openExportModal() {
            const modal = new bootstrap.Modal(document.getElementById('exportModal'));
            modal.show();
        },

        async exportReport(format) {
            const includeGaps = document.getElementById('export-include-gaps').checked;
            const includeSuggestions = document.getElementById('export-include-suggestions').checked;
            const onlySelected = document.getElementById('export-only-selected').checked;

            const params = new URLSearchParams();
            params.set('format', format);
            params.set('include_gaps', includeGaps);
            params.set('include_suggestions', includeSuggestions);

            if (onlySelected && this.state.selected.size > 0) {
                params.set('item_ids', Array.from(this.state.selected).join(','));
            }

            // Add current filters
            const category = document.getElementById('filter-category').value;
            if (category) params.set('category_id', category);

            if (this.state.tab && this.state.tab !== 'all') {
                params.set('tab', this.state.tab);
            }

            bootstrap.Modal.getInstance(document.getElementById('exportModal')).hide();

            // Download file
            window.location.href = `/api/seo/technical-sheet/export?${params}`;
        },

        // ========== PREVIEW & APPLY ==========

        async openPreviewModal(itemId) {
            this.closeAllDropdowns();

            const modal = new bootstrap.Modal(document.getElementById('previewModal'));
            modal.show();

            this.state.previewItemId = itemId;
            document.getElementById('preview-loading').style.display = 'block';
            document.getElementById('preview-content').style.display = 'none';

            try {
                const data = await requestJson(`/api/seo/technical-sheet/items/${encodeURIComponent(itemId)}/preview`, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json'
                    },
                    body: JSON.stringify({})
                });

                document.getElementById('preview-loading').style.display = 'none';

                if (data.success) {
                    this.renderPreview(data);
                    document.getElementById('preview-content').style.display = 'block';
                } else {
                    alert('Erro: ' + (data.error || 'Não há alterações aprovadas para aplicar'));
                    bootstrap.Modal.getInstance(document.getElementById('previewModal')).hide();
                }

            } catch (e) {
                document.getElementById('preview-loading').style.display = 'none';
                alert('Erro: ' + e.message);
            }
        },

        renderPreview(data) {
            const list = document.getElementById('preview-changes-list');

            if (data.changes?.length > 0) {
                list.innerHTML = data.changes.map(change => `
                <div class="d-flex justify-content-between align-items-center p-3 border rounded mb-2">
                    <div>
                        <div class="fw-bold">${this.escapeHtml(change.attribute_name)}</div>
                        <small class="text-muted">${change.attribute_id}</small>
                        ${change.is_new ? '<span class="badge bg-success ms-1">Novo</span>' : ''}
                    </div>
                    <div class="text-end">
                        ${change.current_value ? `
                            <div class="text-decoration-line-through text-muted small">${this.escapeHtml(change.current_value)}</div>
                        ` : '<div class="text-muted small">(vazio)</div>'}
                        <div class="text-success fw-bold">${this.escapeHtml(change.new_value)}</div>
                        <small class="text-muted">${change.confidence}% conf.</small>
                    </div>
                </div>
            `).join('');
                document.getElementById('btn-confirm-apply').disabled = false;
            } else {
                list.innerHTML = '<p class="text-muted">Nenhuma alteração aprovada para aplicar.</p>';
                document.getElementById('btn-confirm-apply').disabled = true;
            }

            // Show warnings if any
            if (data.warnings?.length > 0) {
                const warningsHtml = data.warnings.map(w => `
                <div class="alert alert-${w.severity === 'warning' ? 'warning' : 'info'} py-2 mb-2">
                    <i class="fas fa-${w.severity === 'warning' ? 'exclamation-triangle' : 'info-circle'} me-1"></i>
                    ${this.escapeHtml(w.message)}
                </div>
            `).join('');
                list.insertAdjacentHTML('beforebegin', warningsHtml);
            }

            document.getElementById('preview-score-before').textContent = data.current_score?.toFixed(0) || '-';
            document.getElementById('preview-score-after').textContent = data.estimated_score?.toFixed(0) || '-';
        },

        async confirmApply() {
            if (!this.state.previewItemId) return;

            document.getElementById('btn-confirm-apply').disabled = true;
            document.getElementById('btn-confirm-apply').innerHTML = '<span class="spinner-border spinner-border-sm"></span> Aplicando...';

            try {
                await this.quickApply(this.state.previewItemId);

                bootstrap.Modal.getInstance(document.getElementById('previewModal')).hide();
                this.loadList();

            } catch (e) {
                alert('Erro: ' + e.message);
            } finally {
                document.getElementById('btn-confirm-apply').disabled = false;
                document.getElementById('btn-confirm-apply').innerHTML = '<i class="fas fa-check me-1"></i> Confirmar e Aplicar';
            }
        },

        // ========== UTILITIES ==========

        closeAllDropdowns() {
            document.querySelectorAll('.action-dropdown-menu.show').forEach(menu => {
                menu.classList.remove('show');
            });
        },

        // ========== SEO STRATEGIES INTEGRATION ==========

        async loadSEOAnalysis(itemId) {
            const container = document.getElementById('seo-analysis-container');
            if (!container) return;

            container.innerHTML = `
            <div class="text-center py-3">
                <div class="spinner-border spinner-border-sm text-primary"></div>
                <p class="small text-muted mt-2 mb-0">Analisando SEO...</p>
            </div>
        `;

            try {
                const data = await requestJson(`/api/seo/technical-sheet/items/${encodeURIComponent(itemId)}/seo-analysis`);

                if (data.success) {
                    this.renderSEOAnalysis(container, data, itemId);
                } else {
                    container.innerHTML = `
                    <div class="alert alert-warning py-2 mb-0 small">
                        <i class="fas fa-exclamation-triangle me-1"></i>
                        ${this.escapeHtml(data.error || 'Erro ao analisar SEO')}
                    </div>
                `;
                }
            } catch (e) {
                container.innerHTML = `
                <div class="alert alert-danger py-2 mb-0 small">
                    <i class="fas fa-times-circle me-1"></i>
                    Erro de conexão ao analisar SEO
                </div>
            `;
            }
        },

        renderSEOAnalysis(container, data, itemId) {
            const analysis = data.analysis || {};
            const overallScore = data.score || analysis.overall_score || 0;
            const strategies = analysis.strategies || {};

            const scoreClass = overallScore >= 80 ? 'excellent' : overallScore >= 60 ? 'good' : overallScore >= 40 ? 'warning' : 'critical';

            // Build strategy scores HTML
            const strategyItems = [{
                    key: 'synonyms',
                    name: 'Sinônimos',
                    icon: '📚'
                },
                {
                    key: 'hidden_fields',
                    name: 'Campos Ocultos',
                    icon: '🔮'
                },
                {
                    key: 'search_coverage',
                    name: 'Cobertura Busca',
                    icon: '🔍'
                },
                {
                    key: 'field_weights',
                    name: 'Pesos Campos',
                    icon: '⚖️'
                },
                {
                    key: 'use_contexts',
                    name: 'Contextos Uso',
                    icon: '📍'
                },
                {
                    key: 'long_tail',
                    name: 'Long Tail',
                    icon: '🎯'
                },
                {
                    key: 'compatibility',
                    name: 'Compatibilidade',
                    icon: '🔗'
                },
                {
                    key: 'faq',
                    name: 'FAQs',
                    icon: '❓'
                },
            ].map(s => {
                const stratData = strategies[s.key] || {};
                const score = stratData.score || 0;
                const scoreClass = score >= 70 ? 'high' : score >= 40 ? 'medium' : 'low';
                return `
                <div class="strategy-score-item">
                    <span style="font-size: 0.9rem;">${s.icon}</span>
                    <span class="strategy-name">${s.name}</span>
                    <div class="strategy-bar">
                        <div class="fill ${scoreClass}" style="width: ${score}%"></div>
                    </div>
                    <span class="strategy-score ${scoreClass}">${score}%</span>
                </div>
            `;
            }).join('');

            // Build top suggestions
            const suggestions = data.suggestions || analysis.top_suggestions || [];
            const suggestionsHtml = suggestions.slice(0, 5).map(s => `
            <span class="seo-suggestion-chip" title="${this.escapeHtml(s.reason || s.description || '')}">
                ${this.escapeHtml(s.value || s.keyword || s.suggestion || '-')}
            </span>
        `).join('') || '<span class="text-muted small">Nenhuma sugestão disponível</span>';

            container.innerHTML = `
            <div class="text-center mb-3">
                <div class="seo-score-circle ${scoreClass}">
                    ${Math.round(overallScore)}
                </div>
                <div class="text-muted small mt-2">Score SEO Geral</div>
            </div>

            <div class="mb-3">
                ${strategyItems}
            </div>

            <div class="border-top pt-3">
                <div class="small text-muted mb-2">💡 Sugestões Principais:</div>
                <div>${suggestionsHtml}</div>
            </div>

            <div class="seo-quick-actions">
                <button class="btn btn-sm btn-outline-primary flex-fill" onclick="TechSheet.applySEOSuggestions('${itemId}')" title="Gera sugestões SEO para campos da Ficha Técnica (não aplica no ML automaticamente).">
                    <i class="fas fa-magic"></i> Gerar Sugestões SEO
                </button>
                <button class="btn btn-sm btn-outline-secondary" onclick="TechSheet.showSEOReport('${itemId}')" title="Ver relatório completo">
                    <i class="fas fa-file-alt"></i>
                </button>
            </div>

            <div class="seo-quick-actions mt-2">
                <button class="btn btn-sm btn-outline-success flex-fill" onclick="TechSheet.openSEOTitleOptimizeModal('${itemId}')" title="Pré-visualizar e editar o título antes de aplicar (cria snapshot para rollback)">
                    <i class="fas fa-heading"></i> Pré-visualizar Título
                </button>
                <button class="btn btn-sm btn-outline-success flex-fill" onclick="TechSheet.openSEODescriptionOptimizeModal('${itemId}')" title="Pré-visualizar e editar a descrição antes de aplicar (cria snapshot para rollback)">
                    <i class="fas fa-align-left"></i> Pré-visualizar Descrição
                </button>
                <button class="btn btn-sm btn-outline-dark" onclick="TechSheet.openSEOHistoryModal('${itemId}')" title="Ver histórico e fazer rollback">
                    <i class="fas fa-history"></i>
                </button>
            </div>
        `;
        },

        async openSEOTitleOptimizeModal(itemId) {
            if (!itemId) return;

            this.state.seoTitleModalItemId = itemId;
            this.state.seoTitleOptimizeData = null;

            const modalEl = document.getElementById('seoTitleOptimizeModal');
            const modal = new bootstrap.Modal(modalEl);
            modal.show();

            await this.loadSEOTitleOptimization(itemId);
        },

        async loadSEOTitleOptimization(itemId) {
            const loading = document.getElementById('seo-title-opt-loading');
            const content = document.getElementById('seo-title-opt-content');
            const currentEl = document.getElementById('seo-title-current');
            const inputEl = document.getElementById('seo-title-optimized-input');
            const charEl = document.getElementById('seo-title-charcount');
            const changesEl = document.getElementById('seo-title-changes');
            const applyBtn = document.getElementById('seo-title-apply-btn');

            if (loading) loading.style.display = 'block';
            if (content) content.style.display = 'none';
            if (applyBtn) applyBtn.disabled = true;

            try {
                const data = await requestJson(`/api/seo/technical-sheet/items/${encodeURIComponent(itemId)}/optimize-title`, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json'
                    },
                    body: JSON.stringify({})
                });
                if (!data.success) {
                    throw new Error(data.error || 'Falha ao otimizar título');
                }

                this.state.seoTitleOptimizeData = data;

                const currentTitle = (data.original_title || this.state.currentItem?.item?.title || '').trim();
                const optimizedTitle = (data.optimized_title || '').trim();

                this.state.seoTitleCurrent = currentTitle;

                if (currentEl) currentEl.textContent = currentTitle || '(vazio)';
                if (inputEl) {
                    inputEl.value = optimizedTitle;
                    inputEl.oninput = () => {
                        const v = (inputEl.value || '').trim();
                        if (charEl) charEl.textContent = `${v.length}/60`;
                        if (applyBtn) applyBtn.disabled = v.length === 0 || v.length > 60;
                        this.renderTitleDiff(this.state.seoTitleCurrent, v);
                    };
                    inputEl.oninput();
                }

                // initial diff render
                this.renderTitleDiff(currentTitle, optimizedTitle);

                if (changesEl) {
                    const changes = Array.isArray(data.changes) ? data.changes : [];
                    if (!changes.length) {
                        changesEl.innerHTML = '<span class="text-muted">Nenhuma mudança detectada (pode estar ótimo já).</span>';
                    } else {
                        const formatChange = (c) => {
                            if (typeof c === 'string') return c;
                            if (c && typeof c === 'object') {
                                const action = c.action || c.type || '';
                                const keyword = c.keyword || c.term || c.value || '';
                                const pos = c.position !== undefined ? `@${c.position}` : '';
                                const parts = [action, keyword, pos].filter(Boolean);
                                return parts.join(' ');
                            }
                            return String(c);
                        };

                        changesEl.innerHTML = `
                        <ul class="mb-0 ps-3">
                            ${changes.slice(0, 20).map(c => `<li>${this.escapeHtml(formatChange(c))}</li>`).join('')}
                        </ul>
                    `;
                    }
                }

                if (loading) loading.style.display = 'none';
                if (content) content.style.display = 'block';
            } catch (e) {
                if (loading) loading.style.display = 'none';
                if (content) {
                    content.style.display = 'block';
                    content.innerHTML = `
                    <div class="alert alert-danger py-2 small mb-0">
                        <i class="fas fa-times-circle me-1"></i>
                        ${this.escapeHtml(e.message || 'Erro ao otimizar título')}
                    </div>
                `;
                }
            }
        },

        async regenerateSEOTitle() {
            const itemId = this.state.seoTitleModalItemId;
            if (!itemId) return;
            await this.loadSEOTitleOptimization(itemId);
        },

        async applySEOTitleFromModal() {
            const itemId = this.state.seoTitleModalItemId;
            const inputEl = document.getElementById('seo-title-optimized-input');
            const applyBtn = document.getElementById('seo-title-apply-btn');
            if (!itemId || !inputEl) return;

            const title = (inputEl.value || '').trim();
            if (!title) {
                alert('Título é obrigatório.');
                return;
            }
            if (title.length > 60) {
                alert('Título excede 60 caracteres.');
                return;
            }

            const current = String(this.state.seoTitleCurrent || '').trim();
            if (current !== '' && title === current) {
                alert('O título final está igual ao título atual. Nenhuma alteração para aplicar.');
                return;
            }

            if (applyBtn) applyBtn.disabled = true;

            try {
                const optimizeData = this.state.seoTitleOptimizeData || {};
                const meta = {
                    reason: 'tech_sheet_ui_preview_apply',
                    optimize: {
                        changes_count: Array.isArray(optimizeData.changes) ? optimizeData.changes.length : 0,
                        missing_coverage_types: optimizeData.missing_coverage_types || [],
                    },
                };

                const data = await requestJson(`/api/seo/technical-sheet/items/${encodeURIComponent(itemId)}/apply-optimized-title`, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json'
                    },
                    body: JSON.stringify({
                        title,
                        use_generated: false,
                        meta
                    })
                });

                if (data.success) {
                    const versionId = data.version_id ? ` (v${data.version_id})` : '';
                    this.showToast(`✅ Título aplicado${versionId}`);

                    const modalEl = document.getElementById('seoTitleOptimizeModal');
                    const modal = bootstrap.Modal.getInstance(modalEl);
                    modal?.hide();

                    await this.refreshItemCache(itemId);
                    await this.openDrawer(itemId);
                    if (data.version_id) {
                        await this.openSEOHistoryModal(itemId, data.version_id);
                    }
                } else {
                    alert(data.error || 'Erro ao aplicar título');
                }
            } catch (e) {
                alert('Erro de conexão');
            } finally {
                if (applyBtn) applyBtn.disabled = false;
            }
        },

        async openSEODescriptionOptimizeModal(itemId) {
            if (!itemId) return;

            this.state.seoDescModalItemId = itemId;
            this.state.seoDescOptimizeData = null;

            const modalEl = document.getElementById('seoDescriptionOptimizeModal');
            const modal = new bootstrap.Modal(modalEl);
            modal.show();

            await this.loadSEODescriptionOptimization(itemId);
        },

        async loadSEODescriptionOptimization(itemId) {
            const loading = document.getElementById('seo-desc-opt-loading');
            const content = document.getElementById('seo-desc-opt-content');
            const currentEl = document.getElementById('seo-desc-current');
            const inputEl = document.getElementById('seo-desc-optimized-input');
            const charEl = document.getElementById('seo-desc-charcount');
            const statsEl = document.getElementById('seo-desc-stats');
            const applyBtn = document.getElementById('seo-desc-apply-btn');

            if (loading) loading.style.display = 'block';
            if (content) content.style.display = 'none';
            if (applyBtn) applyBtn.disabled = true;

            try {
                const [descData, optData] = await Promise.all([
                    requestJson(`/api/seo/technical-sheet/items/${encodeURIComponent(itemId)}/description`),
                    requestJson(`/api/seo/technical-sheet/items/${encodeURIComponent(itemId)}/optimize-description`, {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json'
                        },
                        body: JSON.stringify({})
                    })
                ]);

                if (!optData.success) {
                    throw new Error(optData.error || 'Falha ao otimizar descrição');
                }

                this.state.seoDescOptimizeData = optData;

                const currentPlain = (descData.success ? (descData.plain_text || '') : '').trim();
                const optimizedPlain = (optData.optimized_description || '').trim();

                this.state.seoDescCurrent = currentPlain;

                if (statsEl) {
                    const pill = (label, value, cls = 'bg-light') => {
                        return `<span class="badge ${cls} text-dark border">${this.escapeHtml(label)}: ${this.escapeHtml(value)}</span>`;
                    };

                    const stats = [];
                    stats.push(pill('Atual', `${currentPlain.length} chars`));
                    stats.push(pill('Otimizada', `${optimizedPlain.length} chars`, 'bg-success bg-opacity-10'));
                    if (optData.faqs_added !== undefined && optData.faqs_added !== null) {
                        stats.push(pill('FAQs', String(optData.faqs_added), 'bg-info bg-opacity-10'));
                    }
                    if (optData.keywords_injected !== undefined && optData.keywords_injected !== null) {
                        stats.push(pill('Keywords', String(optData.keywords_injected), 'bg-info bg-opacity-10'));
                    }
                    if (optData.density_before !== undefined && optData.density_before !== null) {
                        stats.push(pill('Densidade antes', String(optData.density_before), 'bg-warning bg-opacity-10'));
                    }
                    statsEl.innerHTML = stats.join(' ');
                }

                if (currentEl) currentEl.value = currentPlain;
                if (inputEl) {
                    inputEl.value = optimizedPlain;
                    inputEl.oninput = () => {
                        const v = (inputEl.value || '').trim();
                        if (charEl) charEl.textContent = `${v.length} caracteres`;
                        if (applyBtn) applyBtn.disabled = v.length === 0;
                        this.renderDescriptionDiff(this.state.seoDescCurrent, v);
                    };
                    inputEl.oninput();
                }

                // initial diff render
                this.renderDescriptionDiff(currentPlain, optimizedPlain);

                if (loading) loading.style.display = 'none';
                if (content) content.style.display = 'block';
            } catch (e) {
                if (loading) loading.style.display = 'none';
                if (content) {
                    content.style.display = 'block';
                    content.innerHTML = `
                    <div class="alert alert-danger py-2 small mb-0">
                        <i class="fas fa-times-circle me-1"></i>
                        ${this.escapeHtml(e.message || 'Erro ao otimizar descrição')}
                    </div>
                `;
                }
            }
        },

        async regenerateSEODescription() {
            const itemId = this.state.seoDescModalItemId;
            if (!itemId) return;
            await this.loadSEODescriptionOptimization(itemId);
        },

        async applySEODescriptionFromModal() {
            const itemId = this.state.seoDescModalItemId;
            const inputEl = document.getElementById('seo-desc-optimized-input');
            const applyBtn = document.getElementById('seo-desc-apply-btn');
            if (!itemId || !inputEl) return;

            const plain_text = (inputEl.value || '').trim();
            if (!plain_text) {
                alert('Descrição (plain_text) é obrigatória.');
                return;
            }

            const current = String(this.state.seoDescCurrent || '').trim();
            if (current !== '' && plain_text === current) {
                alert('A descrição final está igual à descrição atual. Nenhuma alteração para aplicar.');
                return;
            }

            if (applyBtn) applyBtn.disabled = true;

            try {
                const optimizeData = this.state.seoDescOptimizeData || {};
                const meta = {
                    reason: 'tech_sheet_ui_preview_apply',
                    optimize: {
                        faqs_added: optimizeData.faqs_added ?? null,
                        keywords_injected: optimizeData.keywords_injected ?? null,
                        density_before: optimizeData.density_before ?? null,
                    },
                };

                const data = await requestJson(`/api/seo/technical-sheet/items/${encodeURIComponent(itemId)}/apply-optimized-description`, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json'
                    },
                    body: JSON.stringify({
                        plain_text,
                        use_generated: false,
                        meta
                    })
                });

                if (data.success) {
                    const versionId = data.version_id ? ` (v${data.version_id})` : '';
                    this.showToast(`✅ Descrição aplicada${versionId}`);

                    const modalEl = document.getElementById('seoDescriptionOptimizeModal');
                    const modal = bootstrap.Modal.getInstance(modalEl);
                    modal?.hide();

                    await this.refreshItemCache(itemId);
                    await this.openDrawer(itemId);
                    if (data.version_id) {
                        await this.openSEOHistoryModal(itemId, data.version_id);
                    }
                } else {
                    alert(data.error || 'Erro ao aplicar descrição');
                }
            } catch (e) {
                alert('Erro de conexão');
            } finally {
                if (applyBtn) applyBtn.disabled = false;
            }
        },

        /**
         * Garante que o cache local (tabela items) reflita o estado atual do anúncio após operações no ML.
         */
        async refreshItemCache(itemId) {
            if (!itemId) return {
                success: false
            };
            try {
                const res = await requestJson(`/api/seo/technical-sheet/items/${encodeURIComponent(itemId)}/refresh`, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json'
                    },
                    body: JSON.stringify({})
                });
                return res;
            } catch (e) {
                return {
                    success: false,
                    error: 'Erro de conexão'
                };
            }
        },

        async applyOptimizedTitleQuick(itemId) {
            if (!itemId) return;
            if (!confirm('Aplicar TÍTULO otimizado no Mercado Livre agora?\n\nIsso altera o anúncio e cria snapshot para rollback.')) return;

            try {
                const data = await requestJson(`/api/seo/technical-sheet/items/${encodeURIComponent(itemId)}/apply-optimized-title`, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json'
                    },
                    body: JSON.stringify({
                        use_generated: true,
                        meta: {
                            reason: 'tech_sheet_ui_quick_apply'
                        }
                    })
                });
                if (data.success) {
                    const versionId = data.version_id ? ` (v${data.version_id})` : '';
                    this.showToast(`✅ Título aplicado${versionId}`);
                    await this.refreshItemCache(itemId);
                    await this.openDrawer(itemId);
                } else {
                    alert(data.error || 'Erro ao aplicar título');
                }
            } catch (e) {
                alert('Erro de conexão');
            }
        },

        async applyOptimizedDescriptionQuick(itemId) {
            if (!itemId) return;
            if (!confirm('Aplicar DESCRIÇÃO otimizada no Mercado Livre agora?\n\nIsso altera o anúncio e cria snapshot para rollback.')) return;

            try {
                const data = await requestJson(`/api/seo/technical-sheet/items/${encodeURIComponent(itemId)}/apply-optimized-description`, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json'
                    },
                    body: JSON.stringify({
                        use_generated: true,
                        meta: {
                            reason: 'tech_sheet_ui_quick_apply'
                        }
                    })
                });
                if (data.success) {
                    const versionId = data.version_id ? ` (v${data.version_id})` : '';
                    this.showToast(`✅ Descrição aplicada${versionId}`);
                    await this.refreshItemCache(itemId);
                    await this.openDrawer(itemId);
                } else {
                    alert(data.error || 'Erro ao aplicar descrição');
                }
            } catch (e) {
                alert('Erro de conexão');
            }
        },

        async openSEOHistoryModal(itemId, focusRowId = null) {
            if (!itemId) return;

            this.state.seoHistoryItemId = itemId;
            this.state.seoHistoryFocusRowId = focusRowId;
            const modalEl = document.getElementById('seoHistoryModal');
            const modal = bootstrap.Modal.getOrCreateInstance(modalEl);
            modal.show();

            const loading = document.getElementById('seo-history-loading');
            const content = document.getElementById('seo-history-content');
            const list = document.getElementById('seo-history-list');

            if (loading) loading.style.display = 'block';
            if (content) content.style.display = 'none';
            if (list) list.innerHTML = '';

            try {
                const data = await requestJson(`/api/seo/technical-sheet/items/${encodeURIComponent(itemId)}/history?limit=30`);

                if (!(data.success)) {
                    throw new Error(data.error || 'Falha ao carregar histórico');
                }

                const rows = Array.isArray(data.data) ? data.data : [];
                this.renderSEOHistory(list, rows, itemId, focusRowId);

                if (loading) loading.style.display = 'none';
                if (content) content.style.display = 'block';
            } catch (e) {
                if (loading) loading.style.display = 'none';
                if (content) content.style.display = 'block';
                if (list) {
                    list.innerHTML = `
                    <div class="alert alert-danger py-2 mb-0 small">
                        <i class="fas fa-times-circle me-1"></i>
                        ${this.escapeHtml(e.message || 'Erro ao carregar histórico')}
                    </div>
                `;
                }
            }
        },

        renderSEOHistory(container, rows, itemId, focusRowId = null) {
            if (!container) return;

            const filtered = rows.filter(r => ['title', 'description', 'attributes', 'images', 'price'].includes(String(r.change_type || '')));
            if (!filtered.length) {
                container.innerHTML = '<p class="text-muted small mb-0">Nenhum histórico encontrado para este item.</p>';
                return;
            }

            const fmtType = (t) => {
                const map = {
                    title: 'Título',
                    description: 'Descrição',
                    attributes: 'Atributos',
                    images: 'Imagens',
                    price: 'Preço'
                };
                return map[t] || String(t || '-');
            };

            container.innerHTML = filtered.map((r) => {
                const id = r.id;
                const version = r.version ?? '-';
                const type = String(r.change_type || '');
                const appliedAt = r.applied_at || r.created_at || '';
                const diff = r.diff || '';
                const canRollback = !!r.can_rollback && !r.rolled_back;
                const rolledBack = !!r.rolled_back;
                const isFocus = focusRowId !== null && String(focusRowId) === String(id);

                return `
                <div id="seo-history-row-${this.escapeHtml(id)}" class="border rounded p-3 mb-2 ${isFocus ? 'border-success bg-success bg-opacity-10' : ''}">
                    <div class="d-flex align-items-start justify-content-between gap-2">
                        <div>
                            <div class="fw-semibold">v${this.escapeHtml(version)} • ${this.escapeHtml(fmtType(type))}</div>
                            <div class="text-muted small">${this.escapeHtml(appliedAt)}</div>
                        </div>
                        <div class="text-end">
                            ${rolledBack ? '<span class="badge bg-secondary">Rollback aplicado</span>' : ''}
                            ${canRollback ? `<button class="btn btn-sm btn-outline-danger" onclick="TechSheet.rollbackSEO('${itemId}', ${id})"><i class=\"fas fa-undo\"></i> Rollback</button>` : ''}
                        </div>
                    </div>
                    ${diff ? `<pre class="small text-muted mb-0 mt-2" style="white-space: pre-wrap;">${this.escapeHtml(diff)}</pre>` : ''}
                </div>
            `;
            }).join('');

            if (focusRowId !== null) {
                const el = document.getElementById(`seo-history-row-${focusRowId}`);
                if (el && typeof el.scrollIntoView === 'function') {
                    setTimeout(() => {
                        try {
                            el.scrollIntoView({
                                behavior: 'smooth',
                                block: 'center'
                            });
                        } catch (e) {
                            // ignore
                        }
                    }, 50);
                }
            }
        },

        async rollbackSEO(itemId, versionId) {
            if (!itemId || !versionId) return;
            if (!confirm(`Fazer rollback da versão #${versionId}?\n\nIsso irá atualizar o anúncio no Mercado Livre.`)) return;

            try {
                const data = await requestJson(`/api/seo/technical-sheet/items/${encodeURIComponent(itemId)}/rollback/${encodeURIComponent(versionId)}`, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json'
                    },
                    body: JSON.stringify({
                        reason: 'Rollback via Ficha Técnica'
                    })
                });

                if (data.success) {
                    this.showToast('✅ Rollback concluído');
                    await this.refreshItemCache(itemId);
                    await this.openDrawer(itemId);
                    await this.openSEOHistoryModal(itemId, versionId);
                } else {
                    alert(data.error || 'Rollback falhou');
                }
            } catch (e) {
                alert('Erro de conexão');
            }
        },

        async applySEOSuggestions(itemId) {
            if (!confirm('Gerar sugestões baseadas nas 12 estratégias SEO para a Ficha Técnica?\n\nObservação: isso não aplica alterações no Mercado Livre automaticamente.')) return;

            const container = document.getElementById('seo-analysis-container');
            const btn = container?.querySelector('.seo-quick-actions button');
            if (btn) {
                btn.disabled = true;
                btn.innerHTML = '<span class="spinner-border spinner-border-sm"></span>';
            }

            try {
                const data = await requestJson(`/api/seo/technical-sheet/items/${encodeURIComponent(itemId)}/seo-suggestions`, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json'
                    }
                });

                if (data.success) {
                    const saved = (data.saved_count !== undefined && data.saved_count !== null) ?
                        Number(data.saved_count) :
                        (Array.isArray(data.suggestions) ? data.suggestions.length : (data.suggestions_created || data.created || 0));

                    this.showToast(`✅ ${saved} sugestões SEO geradas`);
                    await this.openDrawer(itemId); // Refresh drawer
                } else {
                    alert(data.error || 'Erro ao gerar sugestões SEO');
                }
            } catch (e) {
                alert('Erro de conexão');
            } finally {
                if (btn) {
                    btn.disabled = false;
                    btn.innerHTML = '<i class="fas fa-magic"></i> Gerar Sugestões SEO';
                }
            }
        },

        async showSEOReport(itemId) {
            try {
                const data = await requestJson(`/api/seo/technical-sheet/items/${encodeURIComponent(itemId)}/seo-report`);

                if (data.success) {
                    // Open report in new window/modal
                    const reportWindow = window.open('', '_blank', 'width=800,height=600');
                    reportWindow.document.write(`
                    <!DOCTYPE html>
                    <html>
                    <head>
                        <title>SEO Report - ${itemId}</title>
                        <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
                        <style>
                            body { padding: 2rem; font-family: system-ui, sans-serif; }
                            .score-badge { display: inline-block; padding: 0.5rem 1rem; border-radius: 8px; font-weight: bold; }
                            .score-badge.excellent { background: #d4edda; color: #155724; }
                            .score-badge.good { background: #d1ecf1; color: #0c5460; }
                            .score-badge.warning { background: #fff3cd; color: #856404; }
                            .score-badge.critical { background: #f8d7da; color: #721c24; }
                        </style>
                    </head>
                    <body>
                        <h2>📊 Relatório SEO Completo</h2>
                        <p><strong>Item:</strong> ${itemId}</p>
                        <p><strong>Gerado em:</strong> ${new Date().toLocaleString('pt-BR')}</p>
                        <hr>
                        <pre>${JSON.stringify(data.report || data, null, 2)}</pre>
                    </body>
                    </html>
                `);
                } else {
                    alert(data.error || 'Erro ao gerar relatório');
                }
            } catch (e) {
                alert('Erro de conexão');
            }
        },

        escapeHtml(str) {
            if (!str) return '';
            return String(str)
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;');
        }
    };

    // ========================================================================
    // 🚀 BULK SEO - Otimização em Lote de Título e Descrição
    // ========================================================================
    const BulkSEO = {
        // Thresholds
        ASYNC_THRESHOLD: 10, // Acima de 10 itens, usar apply-async
        POLL_INTERVAL_MS: 1500, // Polling a cada 1.5s
        MAX_TITLE_LENGTH: 60,

        state: {
            dryRunResults: null,
            selectedItems: [],
            applyResults: null,
            currentJobId: null,
            pollInterval: null,
            editedValues: {}, // itemId -> { title, description }
        },

        openModal() {
            const selectedIds = TechSheet.state.selectedItems;
            if (!selectedIds || selectedIds.length === 0) {
                alert('Selecione pelo menos um item para otimização em lote.');
                return;
            }

            // Reset state
            this.state.dryRunResults = null;
            this.state.selectedItems = [];
            this.state.applyResults = null;
            this.state.currentJobId = null;
            this.state.editedValues = {};
            this.stopPolling();

            // Update preview
            document.getElementById('bulk-seo-count').textContent = selectedIds.length;
            const previewEl = document.getElementById('bulk-seo-items-preview');
            if (previewEl) {
                const items = selectedIds.slice(0, 10).map(id => {
                    const item = TechSheet.state.items?.find(i => i.item_id === id || i.id === id);
                    const title = item?.title || id;
                    return `<div class="small text-truncate">${TechSheet.escapeHtml(title)}</div>`;
                });
                if (selectedIds.length > 10) {
                    items.push(`<div class="small text-muted">... e mais ${selectedIds.length - 10} itens</div>`);
                }
                previewEl.innerHTML = items.join('');
            }

            // Show options step
            this.showStep('options');

            // Open modal
            const modal = bootstrap.Modal.getOrCreateInstance(document.getElementById('bulkSeoModal'));
            modal.show();
        },

        showStep(step) {
            document.querySelectorAll('.bulk-seo-step').forEach(el => el.style.display = 'none');
            const stepEl = document.getElementById(`bulk-seo-step-${step}`);
            if (stepEl) stepEl.style.display = 'block';

            // Update buttons
            const btnBack = document.getElementById('bulk-seo-btn-back');
            const btnApply = document.getElementById('bulk-seo-btn-apply');

            if (btnBack) btnBack.style.display = (step === 'review') ? 'inline-block' : 'none';
            if (btnApply) btnApply.style.display = (step === 'review') ? 'inline-block' : 'none';
        },

        async runDryRun() {
            const selectedIds = TechSheet.state.selectedItems;
            if (!selectedIds || selectedIds.length === 0) {
                alert('Nenhum item selecionado');
                return;
            }

            const optimizeTitle = document.getElementById('bulk-seo-optimize-title')?.checked ?? true;
            const optimizeDescription = document.getElementById('bulk-seo-optimize-description')?.checked ?? true;

            if (!optimizeTitle && !optimizeDescription) {
                alert('Selecione pelo menos uma opção (título ou descrição)');
                return;
            }

            this.showStep('loading');
            document.getElementById('bulk-seo-loading-status').textContent = 'Iniciando análise...';
            document.getElementById('bulk-seo-loading-progress').style.width = '10%';

            try {
                const data = await requestJson('/api/seo/technical-sheet/bulk/dry-run', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json'
                    },
                    body: JSON.stringify({
                        item_ids: selectedIds,
                        optimize_title: optimizeTitle,
                        optimize_description: optimizeDescription,
                    }),
                });

                if (!data.success) {
                    throw new Error(data.error || 'Falha no dry-run');
                }

                this.state.dryRunResults = data;
                this.state.editedValues = {}; // Reset edits
                this.renderReviewStep(data);
                this.showStep('review');

            } catch (e) {
                alert('Erro: ' + (e.message || 'Falha ao executar dry-run'));
                this.showStep('options');
            }
        },

        renderReviewStep(data) {
            const stats = data.stats || {};

            // Update stats
            document.getElementById('bulk-seo-stat-changes').textContent = stats.has_changes || 0;
            document.getElementById('bulk-seo-stat-noop').textContent = stats.no_op || 0;
            document.getElementById('bulk-seo-stat-risk').textContent = (stats.high_risk || 0) + (stats.medium_risk || 0);
            document.getElementById('bulk-seo-stat-errors').textContent = stats.errors || 0;

            const listEl = document.getElementById('bulk-seo-items-list');
            if (!listEl) return;

            const items = data.items || {};
            const itemIds = Object.keys(items);

            let html = '';
            for (const itemId of itemIds) {
                const item = items[itemId];
                const hasChanges = item.has_changes;
                const hasError = !item.success;
                const risk = item.risk_level || 'none';

                let cssClass = '';
                if (hasError) cssClass = 'has-error';
                else if (!hasChanges) cssClass = 'no-op';
                else if (risk === 'high' || risk === 'medium') cssClass = 'has-risk';
                else if (hasChanges) cssClass = 'has-changes';

                const titleItem = TechSheet.state.items?.find(i => i.item_id === itemId || i.id === itemId);
                const displayTitle = titleItem?.title || item.current?.title || itemId;

                html += `
                <div class="bulk-seo-item ${cssClass}" data-item-id="${TechSheet.escapeHtml(itemId)}">
                    <div class="item-header">
                        ${hasChanges && !hasError ? `
                            <input type="checkbox" class="form-check-input bulk-seo-checkbox"
                                   data-item-id="${TechSheet.escapeHtml(itemId)}"
                                   onchange="BulkSEO.updateSelectedCount()">
                        ` : ''}
                        <span class="item-title">${TechSheet.escapeHtml(displayTitle)}</span>
                        <small class="text-muted">${TechSheet.escapeHtml(itemId)}</small>
                        ${this.renderStatusBadge(item)}
                    </div>
                    ${this.renderItemDiffs(itemId, item)}
                </div>
            `;
            }

            listEl.innerHTML = html || '<p class="text-muted">Nenhum item processado</p>';
            this.updateSelectedCount();
        },

        renderStatusBadge(item) {
            if (!item.success) {
                return `<span class="badge bg-danger">Erro: ${TechSheet.escapeHtml(item.error || 'Falha')}</span>`;
            }
            if (!item.has_changes) {
                return '<span class="badge bg-secondary">Sem mudança</span>';
            }
            const risk = item.risk_level || 'none';
            if (risk === 'high') {
                return '<span class="risk-badge high">⚠️ Risco Alto</span>';
            }
            if (risk === 'medium') {
                return '<span class="risk-badge medium">⚠️ Risco Médio</span>';
            }
            return '<span class="badge bg-success">OK</span>';
        },

        renderItemDiffs(itemId, item) {
            if (!item.success || !item.has_changes) return '';

            let html = '';
            const escapedItemId = TechSheet.escapeHtml(itemId);

            // Title diff with editable field
            if (item.changes?.title && item.suggested?.title) {
                const suggestedTitle = item.suggested?.title || '';
                const charCount = suggestedTitle.length;
                const charClass = charCount > this.MAX_TITLE_LENGTH ? 'danger' : (charCount > 55 ? 'warn' : '');

                html += `
                <div class="bulk-seo-diff">
                    <div class="bulk-seo-field-toggle">
                        <input type="checkbox" class="form-check-input bulk-seo-field-check"
                               id="apply-title-${escapedItemId}"
                               data-item-id="${escapedItemId}" data-field="title" checked>
                        <label class="form-check-label fw-bold small" for="apply-title-${escapedItemId}">
                            📝 Aplicar Título
                        </label>
                    </div>
                    <div class="diff-before small mb-2">
                        <strong>Atual:</strong> ${TechSheet.escapeHtml(item.current?.title || '')}
                        <span class="text-muted">(${item.current?.title_length || 0} chars)</span>
                    </div>
                    <textarea class="bulk-seo-editable title-field"
                              data-item-id="${escapedItemId}"
                              data-field="title"
                              onkeyup="BulkSEO.onEditField(this)"
                              maxlength="70">${TechSheet.escapeHtml(suggestedTitle)}</textarea>
                    <div class="bulk-seo-char-count ${charClass}" id="char-count-title-${escapedItemId}">
                        ${charCount}/${this.MAX_TITLE_LENGTH} caracteres
                    </div>
                </div>
            `;
            }

            // Description diff with editable field
            if (item.changes?.description && item.suggested?.description) {
                const suggestedDesc = item.suggested?.description || '';

                html += `
                <div class="bulk-seo-diff mt-2">
                    <div class="bulk-seo-field-toggle">
                        <input type="checkbox" class="form-check-input bulk-seo-field-check"
                               id="apply-desc-${escapedItemId}"
                               data-item-id="${escapedItemId}" data-field="description" checked>
                        <label class="form-check-label fw-bold small" for="apply-desc-${escapedItemId}">
                            📄 Aplicar Descrição
                        </label>
                    </div>
                    <div class="diff-before small mb-2">
                        <strong>Atual:</strong> ${item.current?.description_length || 0} caracteres
                    </div>
                    <textarea class="bulk-seo-editable"
                              data-item-id="${escapedItemId}"
                              data-field="description"
                              onkeyup="BulkSEO.onEditField(this)"
                              rows="4">${TechSheet.escapeHtml(suggestedDesc)}</textarea>
                    <div class="bulk-seo-char-count" id="char-count-desc-${escapedItemId}">
                        ${suggestedDesc.length} caracteres
                    </div>
                </div>
            `;
            }

            // Risk summary
            if (item.risk_summary && item.risk_summary !== 'Sem riscos detectados') {
                html += `
                <div class="mt-2 small text-warning">
                    <i class="fas fa-exclamation-triangle"></i> ${TechSheet.escapeHtml(item.risk_summary)}
                </div>
            `;
            }

            return html;
        },

        onEditField(el) {
            const itemId = el.dataset.itemId;
            const field = el.dataset.field;
            const value = el.value;

            if (!this.state.editedValues[itemId]) {
                this.state.editedValues[itemId] = {};
            }
            this.state.editedValues[itemId][field] = value;

            // Update char count
            const charCountEl = document.getElementById(`char-count-${field}-${itemId}`);
            if (charCountEl) {
                const len = value.length;
                if (field === 'title') {
                    charCountEl.textContent = `${len}/${this.MAX_TITLE_LENGTH} caracteres`;
                    charCountEl.className = 'bulk-seo-char-count';
                    if (len > this.MAX_TITLE_LENGTH) charCountEl.classList.add('danger');
                    else if (len > 55) charCountEl.classList.add('warn');
                } else {
                    charCountEl.textContent = `${len} caracteres`;
                }
            }
        },

        selectAllChanges() {
            document.querySelectorAll('.bulk-seo-checkbox').forEach(cb => {
                cb.checked = true;
            });
            this.updateSelectedCount();
        },

        deselectAll() {
            document.querySelectorAll('.bulk-seo-checkbox').forEach(cb => {
                cb.checked = false;
            });
            this.updateSelectedCount();
        },

        updateSelectedCount() {
            const checked = document.querySelectorAll('.bulk-seo-checkbox:checked');
            document.getElementById('bulk-seo-selected-for-apply').textContent = `${checked.length} selecionados para aplicar`;
        },

        backToOptions() {
            this.showStep('options');
        },

        async applySelected() {
            const checkboxes = document.querySelectorAll('.bulk-seo-checkbox:checked');
            if (checkboxes.length === 0) {
                alert('Nenhum item selecionado para aplicar');
                return;
            }

            if (!confirm(`Aplicar otimizações em ${checkboxes.length} itens?\n\nIsso irá atualizar os anúncios no Mercado Livre.`)) {
                return;
            }

            const itemsToApply = [];
            const dryRunItems = this.state.dryRunResults?.items || {};

            checkboxes.forEach(cb => {
                const itemId = cb.dataset.itemId;
                const dryRunItem = dryRunItems[itemId];
                if (!dryRunItem || !dryRunItem.has_changes) return;

                // Check which fields to apply
                const applyTitleCheckbox = document.getElementById(`apply-title-${itemId}`);
                const applyDescCheckbox = document.getElementById(`apply-desc-${itemId}`);

                const applyTitle = applyTitleCheckbox ? applyTitleCheckbox.checked : (dryRunItem.changes?.title || false);
                const applyDescription = applyDescCheckbox ? applyDescCheckbox.checked : (dryRunItem.changes?.description || false);

                // Get edited or original values
                const edited = this.state.editedValues[itemId] || {};
                const title = edited.title ?? dryRunItem.suggested?.title ?? null;
                const description = edited.description ?? dryRunItem.suggested?.description ?? null;

                itemsToApply.push({
                    item_id: itemId,
                    apply_title: applyTitle,
                    apply_description: applyDescription,
                    title: applyTitle ? title : null,
                    description: applyDescription ? description : null,
                });
            });

            if (itemsToApply.length === 0) {
                alert('Nenhum item válido para aplicar');
                return;
            }

            // Decide sync vs async
            if (itemsToApply.length <= this.ASYNC_THRESHOLD) {
                await this.applySynchronous(itemsToApply);
            } else {
                await this.applyAsynchronous(itemsToApply);
            }
        },

        async applySynchronous(itemsToApply) {
            this.showStep('applying');
            document.getElementById('bulk-seo-applying-status').textContent = `Aplicando em ${itemsToApply.length} itens...`;
            document.getElementById('bulk-seo-applying-progress').style.width = '20%';

            try {
                const data = await requestJson('/api/seo/technical-sheet/bulk/apply', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json'
                    },
                    body: JSON.stringify({
                        items: itemsToApply,
                        reason: 'Bulk SEO via Tech Sheet UI',
                    }),
                });
                document.getElementById('bulk-seo-applying-progress').style.width = '100%';

                if (!data.success && !data.stats) {
                    throw new Error(data.error || 'Falha na aplicação');
                }

                this.state.applyResults = data;
                this.renderResultsStep(data);
                this.showStep('results');

                // Refresh list
                await TechSheet.loadList();

            } catch (e) {
                alert('Erro: ' + (e.message || 'Falha ao aplicar'));
                this.showStep('review');
            }
        },

        async applyAsynchronous(itemsToApply) {
            this.showStep('applying');
            document.getElementById('bulk-seo-applying-status').textContent = `Iniciando job para ${itemsToApply.length} itens...`;
            document.getElementById('bulk-seo-applying-progress').style.width = '5%';

            try {
                const data = await requestJson('/api/seo/technical-sheet/bulk/apply-async', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json'
                    },
                    body: JSON.stringify({
                        items: itemsToApply,
                        reason: 'Bulk SEO via Tech Sheet UI (async)',
                    }),
                });

                if (!data.success) {
                    throw new Error(data.error || 'Falha ao iniciar job');
                }

                // If executed synchronously (small batch), show results directly
                if (data.executed_sync && data.result) {
                    this.state.applyResults = data.result;
                    document.getElementById('bulk-seo-applying-progress').style.width = '100%';
                    this.renderResultsStep(data.result);
                    this.showStep('results');
                    await TechSheet.loadList();
                    return;
                }

                // Start polling for async job
                this.state.currentJobId = data.job_id;
                document.getElementById('bulk-seo-applying-status').textContent = `Job ${data.job_id} iniciado. Aguardando processamento...`;
                document.getElementById('bulk-seo-applying-progress').style.width = '10%';

                this.startPolling();

            } catch (e) {
                alert('Erro: ' + (e.message || 'Falha ao iniciar job assíncrono'));
                this.showStep('review');
            }
        },

        startPolling() {
            this.stopPolling();
            this.pollInterval = setInterval(() => this.pollJobStatus(), this.POLL_INTERVAL_MS);
        },

        stopPolling() {
            if (this.pollInterval) {
                clearInterval(this.pollInterval);
                this.pollInterval = null;
            }
        },

        async pollJobStatus() {
            if (!this.state.currentJobId) {
                this.stopPolling();
                return;
            }

            try {
                const data = await requestJson(`/api/seo/technical-sheet/bulk/job/${this.state.currentJobId}/status`);

                if (!data.success) {
                    console.error('Erro ao buscar status do job:', data.error);
                    return;
                }

                const job = data.job;
                const total = job.total_items || 1;
                const processed = job.processed_items || 0;
                const percent = Math.round((processed / total) * 100);

                document.getElementById('bulk-seo-applying-progress').style.width = `${Math.max(10, percent)}%`;
                document.getElementById('bulk-seo-applying-status').textContent =
                    `Processando: ${processed}/${total} itens (${percent}%)`;

                // Check if completed
                if (job.status === 'completed' || job.status === 'failed') {
                    this.stopPolling();

                    const results = job.results || {};
                    this.state.applyResults = results;

                    document.getElementById('bulk-seo-applying-progress').style.width = '100%';

                    if (job.status === 'failed' && !results.stats) {
                        results.stats = {
                            titles_applied: job.successful_items || 0,
                            descriptions_applied: 0,
                            errors: job.failed_items || 0,
                            version_ids: [],
                        };
                        results.error = 'Job falhou';
                    }

                    this.renderResultsStep(results, job.status === 'failed');
                    this.showStep('results');

                    await TechSheet.loadList();
                }

            } catch (e) {
                console.error('Erro no polling:', e);
            }
        },

        renderResultsStep(data, hasFailed = false) {
            const stats = data.stats || {};

            document.getElementById('bulk-seo-result-titles').textContent = stats.titles_applied || 0;
            document.getElementById('bulk-seo-result-descs').textContent = stats.descriptions_applied || 0;
            document.getElementById('bulk-seo-result-errors').textContent = stats.errors || 0;

            const versionIds = stats.version_ids || [];
            document.getElementById('bulk-seo-result-versions').textContent = versionIds.length;

            // Update alert based on status
            const alertEl = document.getElementById('bulk-seo-result-alert');
            if (hasFailed) {
                alertEl.className = 'alert alert-danger mb-4';
                alertEl.innerHTML = '<i class="fas fa-times-circle me-2"></i><strong>Processamento falhou!</strong>';
            } else if (stats.errors > 0) {
                alertEl.className = 'alert alert-warning mb-4';
                alertEl.innerHTML = '<i class="fas fa-exclamation-triangle me-2"></i><strong>Otimização concluída com erros</strong>';
            } else {
                alertEl.className = 'alert alert-success mb-4';
                alertEl.innerHTML = '<i class="fas fa-check-circle me-2"></i><strong>Otimização concluída!</strong>';
            }

            // Rollback section
            const rollbackSection = document.getElementById('bulk-seo-rollback-section');
            if (versionIds.length > 0) {
                document.getElementById('bulk-seo-rollback-count').textContent = versionIds.length;
                rollbackSection.style.display = 'block';
            } else {
                rollbackSection.style.display = 'none';
            }

            // Details
            const detailsEl = document.getElementById('bulk-seo-result-details');
            if (!detailsEl) return;

            const items = data.items || {};
            const failures = data.failures || [];

            let html = '';

            // Success items
            for (const [itemId, result] of Object.entries(items)) {
                if (result.success) {
                    const resultVersionIds = (result.version_ids || []);
                    const versionLinks = resultVersionIds.map(v =>
                        `<a href="#" class="bulk-seo-version-link" onclick="BulkSEO.showVersionHistory('${itemId}', ${v}); return false;">v${v}</a>`
                    ).join(', ');

                    html += `
                    <div class="alert alert-success py-2 mb-2 d-flex align-items-center justify-content-between">
                        <div>
                            <strong>${TechSheet.escapeHtml(itemId)}</strong>:
                            ${result.title_applied ? '✅ Título' : ''}
                            ${result.description_applied ? '✅ Descrição' : ''}
                            ${versionLinks ? `<small class="text-muted ms-2">(${versionLinks})</small>` : ''}
                        </div>
                        ${resultVersionIds.length > 0 ? `
                            <button class="btn btn-outline-secondary bulk-seo-rollback-btn"
                                    onclick="BulkSEO.rollbackItem('${itemId}', [${resultVersionIds.join(',')}])">
                                <i class="fas fa-undo"></i>
                            </button>
                        ` : ''}
                    </div>
                `;
                } else if (result.status === 'no_op') {
                    html += `
                    <div class="alert alert-secondary py-2 mb-2">
                        <strong>${TechSheet.escapeHtml(itemId)}</strong>: Sem alteração
                    </div>
                `;
                }
            }

            // Failures
            for (const failure of failures) {
                html += `
                <div class="alert alert-danger py-2 mb-2">
                    <strong>${TechSheet.escapeHtml(failure.item_id)}</strong>:
                    ❌ ${TechSheet.escapeHtml(failure.error || 'Erro desconhecido')}
                </div>
            `;
            }

            detailsEl.innerHTML = html || '<p class="text-muted">Nenhum detalhe disponível</p>';
        },

        async showVersionHistory(itemId, versionId) {
            // Feature in development: Open drawer with version history
            alert(`Histórico da versão ${versionId} do item ${itemId} - funcionalidade em desenvolvimento.`);
        },

        async rollbackItem(itemId, versionIds) {
            if (!confirm(`Reverter ${versionIds.length} versão(ões) do item ${itemId}?`)) {
                return;
            }

            try {
                const data = await requestJson('/api/seo/technical-sheet/bulk/rollback', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json'
                    },
                    body: JSON.stringify({
                        version_ids: versionIds,
                        reason: 'Rollback via Bulk SEO UI',
                    }),
                });

                if (data.success) {
                    alert(`Rollback concluído: ${data.stats?.successful || 0} versões revertidas.`);
                    await TechSheet.loadList();
                } else {
                    alert('Erro: ' + (data.error || 'Falha no rollback'));
                }
            } catch (e) {
                alert('Erro: ' + (e.message || 'Falha ao executar rollback'));
            }
        },

        async showRollbackConfirm() {
            const versionIds = this.state.applyResults?.stats?.version_ids || [];
            if (versionIds.length === 0) {
                alert('Nenhuma versão disponível para rollback.');
                return;
            }

            if (!confirm(`Reverter TODAS as ${versionIds.length} alterações aplicadas?\n\nIsso irá desfazer todas as otimizações aplicadas nesta sessão.`)) {
                return;
            }

            try {
                const data = await requestJson('/api/seo/technical-sheet/bulk/rollback', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json'
                    },
                    body: JSON.stringify({
                        version_ids: versionIds,
                        reason: 'Rollback completo via Bulk SEO UI',
                    }),
                });

                if (data.success) {
                    alert(`Rollback completo concluído!\n\n✅ ${data.stats?.successful || 0} revertidas\n❌ ${data.stats?.failed || 0} falhas`);
                    document.getElementById('bulk-seo-rollback-section').style.display = 'none';
                    await TechSheet.loadList();
                } else {
                    alert('Erro: ' + (data.error || 'Falha no rollback'));
                }
            } catch (e) {
                alert('Erro: ' + (e.message || 'Falha ao executar rollback completo'));
            }
        },
    };

    // Close dropdowns when clicking outside
    document.addEventListener('click', () => TechSheet.closeAllDropdowns());

    // Initialize on page load
    document.addEventListener('DOMContentLoaded', () => TechSheet.init());
</script>
