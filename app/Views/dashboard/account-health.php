<?php

declare(strict_types=1);

/**
 * Diagnóstico da Conta - Página Única de Saúde da Conta ML
 * Mostra score geral, 5 pilares, ações prioritárias e itens com problemas
 */
$pageTitle = 'Diagnóstico da Conta';
$currentPage = 'account-health';
?>

<style>
    /* =============================================
   ACCOUNT HEALTH - DIAGNOSTIC PAGE
   ============================================= */

    /* Hero Score */
    .health-hero {
        background: linear-gradient(135deg, #1a1a2e 0%, #16213e 50%, #0f3460 100%);
        border-radius: 1.25rem;
        padding: 2.5rem;
        color: white;
        margin-bottom: 2rem;
        position: relative;
        overflow: hidden;
    }

    .health-hero::before {
        content: '';
        position: absolute;
        top: -50%;
        right: -20%;
        width: 500px;
        height: 500px;
        background: radial-gradient(circle, rgba(255, 255, 255, 0.03) 0%, transparent 70%);
        border-radius: 50%;
    }

    .score-circle {
        width: 180px;
        height: 180px;
        border-radius: 50%;
        display: flex;
        align-items: center;
        justify-content: center;
        flex-direction: column;
        position: relative;
        margin: 0 auto;
    }

    .score-circle svg {
        position: absolute;
        top: 0;
        left: 0;
        width: 100%;
        height: 100%;
        transform: rotate(-90deg);
    }

    .score-circle .score-bg {
        fill: none;
        stroke: rgba(255, 255, 255, 0.1);
        stroke-width: 8;
    }

    .score-circle .score-fill {
        fill: none;
        stroke-width: 8;
        stroke-linecap: round;
        transition: stroke-dashoffset 1.5s ease-in-out, stroke 0.5s;
    }

    .score-value {
        font-size: 3rem;
        font-weight: 700;
        line-height: 1;
        z-index: 1;
    }

    .score-label {
        font-size: 0.875rem;
        opacity: 0.8;
        z-index: 1;
    }

    /* Score colors */
    .score-critical {
        --score-color: #ef4444;
    }

    .score-warning {
        --score-color: #f59e0b;
    }

    .score-good {
        --score-color: #22c55e;
    }

    .score-great {
        --score-color: #06b6d4;
    }

    .score-circle .score-fill {
        stroke: var(--score-color);
    }

    .score-value {
        color: var(--score-color);
    }

    /* Summary cards */
    .summary-stat {
        text-align: center;
        padding: 0.75rem;
    }

    .summary-stat .stat-value {
        font-size: 1.5rem;
        font-weight: 700;
    }

    .summary-stat .stat-label {
        font-size: 0.75rem;
        opacity: 0.7;
        text-transform: uppercase;
        letter-spacing: 0.5px;
    }

    /* Data Quality Badge */
    .data-quality-badge {
        display: inline-flex;
        align-items: center;
        gap: 0.375rem;
        padding: 0.25rem 0.625rem;
        border-radius: 0.5rem;
        font-size: 0.75rem;
        font-weight: 600;
        line-height: 1.2;
        white-space: nowrap;
        animation: slideInDown 0.4s ease-out;
    }

    .data-quality-badge.real {
        background: rgba(34, 197, 94, 0.15);
        color: #22c55e;
        border: 1px solid rgba(34, 197, 94, 0.3);
    }

    .data-quality-badge.partial {
        background: rgba(245, 158, 11, 0.15);
        color: #f59e0b;
        border: 1px solid rgba(245, 158, 11, 0.3);
    }

    .data-quality-badge.mock {
        background: rgba(239, 68, 68, 0.15);
        color: #ef4444;
        border: 1px solid rgba(239, 68, 68, 0.3);
    }

    .data-quality-badge i {
        font-size: 0.875rem;
    }

    @keyframes slideInDown {
        from {
            opacity: 0;
            transform: translateY(-8px);
        }

        to {
            opacity: 1;
            transform: translateY(0);
        }
    }

    /* Pillar Cards */
    .pillar-card {
        border: none;
        border-radius: 1rem;
        transition: all 0.3s ease;
        cursor: pointer;
        position: relative;
        overflow: hidden;
    }

    .pillar-card:hover {
        transform: translateY(-4px);
        box-shadow: 0 12px 40px rgba(0, 0, 0, 0.12);
    }

    .pillar-card .card-body {
        padding: 1.25rem;
    }

    .pillar-score-bar {
        height: 6px;
        background: #e5e7eb;
        border-radius: 3px;
        overflow: hidden;
        margin-top: 0.75rem;
    }

    .pillar-score-fill {
        height: 100%;
        border-radius: 3px;
        transition: width 1.5s ease-in-out;
    }

    .pillar-icon {
        width: 48px;
        height: 48px;
        border-radius: 12px;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 1.25rem;
    }

    .pillar-score-value {
        font-size: 1.75rem;
        font-weight: 700;
        line-height: 1;
    }

    .pillar-issues-badge {
        font-size: 0.7rem;
        padding: 0.2rem 0.5rem;
        border-radius: 10px;
    }

    /* Action Items */
    .action-item {
        border: 1px solid #e5e7eb;
        border-radius: 0.75rem;
        padding: 1rem 1.25rem;
        margin-bottom: 0.75rem;
        transition: all 0.2s ease;
        background: white;
    }

    .action-item:hover {
        border-color: #d1d5db;
        box-shadow: 0 4px 12px rgba(0, 0, 0, 0.06);
    }

    .action-item.severity-critical {
        border-left: 4px solid #ef4444;
    }

    .action-item.severity-warning {
        border-left: 4px solid #f59e0b;
    }

    .action-item.severity-info {
        border-left: 4px solid #3b82f6;
    }

    .severity-badge {
        font-size: 0.65rem;
        padding: 0.15rem 0.5rem;
        border-radius: 8px;
        text-transform: uppercase;
        font-weight: 600;
        letter-spacing: 0.5px;
    }

    .severity-badge.critical {
        background: #fef2f2;
        color: #dc2626;
    }

    .severity-badge.warning {
        background: #fffbeb;
        color: #d97706;
    }

    .severity-badge.info {
        background: #eff6ff;
        color: #2563eb;
    }

    /* Items Table */
    .item-row {
        border: 1px solid #e5e7eb;
        border-radius: 0.75rem;
        padding: 0.75rem;
        margin-bottom: 0.5rem;
        background: white;
        transition: all 0.2s;
    }

    .item-row:hover {
        border-color: #3b82f6;
        box-shadow: 0 2px 8px rgba(59, 130, 246, 0.1);
    }

    .item-thumb {
        width: 56px;
        height: 56px;
        border-radius: 8px;
        object-fit: cover;
        border: 1px solid #e5e7eb;
    }

    .item-score-mini {
        width: 40px;
        height: 40px;
        border-radius: 50%;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 0.75rem;
        font-weight: 700;
    }

    /* Price comparison */
    .price-diff-positive {
        color: #ef4444;
    }

    .price-diff-negative {
        color: #22c55e;
    }

    .price-diff-neutral {
        color: #6b7280;
    }

    /* Attention cards */
    .attention-card {
        border-radius: 0.75rem;
        padding: 1rem;
        display: flex;
        align-items: center;
        gap: 1rem;
        margin-bottom: 0.5rem;
    }

    .attention-card.severity-critical {
        background: #fef2f2;
        border: 1px solid #fecaca;
    }

    .attention-card.severity-warning {
        background: #fffbeb;
        border: 1px solid #fde68a;
    }

    /* Stale items */
    .stale-item {
        display: flex;
        align-items: center;
        gap: 1rem;
        padding: 0.75rem 1rem;
        border-radius: 0.75rem;
        margin-bottom: 0.5rem;
        transition: all 0.2s ease;
    }

    .stale-item:hover {
        transform: translateX(4px);
    }

    .stale-item.severity-critical {
        background: #fef2f2;
        border: 1px solid #fecaca;
    }

    .stale-item.severity-warning {
        background: #fffbeb;
        border: 1px solid #fde68a;
    }

    .stale-item-thumb {
        width: 56px;
        height: 56px;
        border-radius: 0.5rem;
        object-fit: cover;
        flex-shrink: 0;
        background: #f3f4f6;
    }

    .stale-item-info {
        flex: 1;
        min-width: 0;
    }

    .stale-item-title {
        font-weight: 600;
        font-size: 0.9rem;
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
    }

    .stale-item-meta {
        font-size: 0.8rem;
        color: #6b7280;
        display: flex;
        gap: 0.75rem;
        flex-wrap: wrap;
    }

    .stale-item-actions {
        display: flex;
        gap: 0.5rem;
        flex-shrink: 0;
    }

    .stale-hidden {
        display: none;
    }

    /* Skeleton loading */
    .skeleton {
        background: linear-gradient(90deg, #f0f0f0 25%, #e0e0e0 50%, #f0f0f0 75%);
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

    .skeleton-circle {
        border-radius: 50%;
    }

    /* Section headers */
    .section-header {
        display: flex;
        align-items: center;
        justify-content: space-between;
        margin-bottom: 1.25rem;
    }

    .section-header h5 {
        font-weight: 600;
        margin: 0;
        display: flex;
        align-items: center;
        gap: 0.5rem;
    }

    .section-header .section-count {
        background: #e5e7eb;
        color: #374151;
        font-size: 0.75rem;
        padding: 0.15rem 0.5rem;
        border-radius: 10px;
        font-weight: 600;
    }

    /* Responsive */
    @media (max-width: 768px) {
        .health-hero {
            padding: 1.5rem;
        }

        .score-circle {
            width: 140px;
            height: 140px;
        }

        .score-value {
            font-size: 2.5rem;
        }

        .pillar-card .card-body {
            padding: 1rem;
        }
    }

    /* Dark mode support */
    [data-theme="dark"] .action-item,
    [data-theme="dark"] .item-row {
        background: var(--bs-body-bg);
        border-color: var(--bs-border-color);
    }

    [data-theme="dark"] .pillar-score-bar {
        background: var(--bs-border-color);
    }

    [data-theme="dark"] .stale-item.severity-critical {
        background: rgba(220, 38, 38, 0.1);
        border-color: rgba(220, 38, 38, 0.3);
    }

    [data-theme="dark"] .stale-item.severity-warning {
        background: rgba(234, 179, 8, 0.1);
        border-color: rgba(234, 179, 8, 0.3);
    }

    /* Reputation Detail Panel */
    .rep-level-badge {
        display: inline-flex;
        align-items: center;
        gap: 0.5rem;
        padding: 0.5rem 1rem;
        border-radius: 2rem;
        font-weight: 600;
        font-size: 0.875rem;
    }

    .rep-level-5 {
        background: #dcfce7;
        color: #166534;
    }

    .rep-level-4 {
        background: #d1fae5;
        color: #065f46;
    }

    .rep-level-3 {
        background: #fef9c3;
        color: #854d0e;
    }

    .rep-level-2 {
        background: #fed7aa;
        color: #9a3412;
    }

    .rep-level-1 {
        background: #fecaca;
        color: #991b1b;
    }

    .rep-metric-card {
        border-radius: 0.75rem;
        border: 1px solid #e5e7eb;
        padding: 1rem;
        text-align: center;
    }

    .rep-metric-value {
        font-size: 1.5rem;
        font-weight: 700;
    }

    .rep-metric-label {
        font-size: 0.75rem;
        color: #6b7280;
        text-transform: uppercase;
        letter-spacing: 0.3px;
    }

    /* Ratings donut container */
    .ratings-donut {
        position: relative;
        width: 120px;
        height: 120px;
        margin: 0 auto;
    }

    .ratings-donut canvas {
        width: 100% !important;
        height: 100% !important;
    }

    .ratings-center {
        position: absolute;
        top: 50%;
        left: 50%;
        transform: translate(-50%, -50%);
        text-align: center;
    }

    /* Quick action buttons (inline usage) - see full definition below */

    /* Conversion funnel */
    .funnel-metric {
        background: #f8fafc;
        border-radius: 0.75rem;
        padding: 1rem;
        text-align: center;
        border: 1px solid #e2e8f0;
    }

    .funnel-metric .funnel-value {
        font-size: 1.75rem;
        font-weight: 700;
    }

    .funnel-metric .funnel-label {
        font-size: 0.75rem;
        color: #64748b;
        text-transform: uppercase;
        letter-spacing: 0.3px;
    }

    .funnel-metric .funnel-compare {
        font-size: 0.75rem;
        margin-top: 0.25rem;
    }

    /* Auto-refresh indicator */
    .auto-refresh-badge {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        width: 18px;
        height: 18px;
        border-radius: 50%;
        background: rgba(34, 197, 94, 0.15);
        color: #22c55e;
        font-size: 0.65rem;
        margin-left: 4px;
        animation: spin-slow 3s linear infinite;
    }

    @keyframes spin-slow {
        from {
            transform: rotate(0deg);
        }

        to {
            transform: rotate(360deg);
        }
    }

    /* Action item completion */
    .action-item.completed {
        opacity: 0.5;
        position: relative;
        transition: opacity 0.3s ease, transform 0.3s ease;
    }

    .action-item.completed::after {
        content: '\2713 Concluído';
        position: absolute;
        top: 0.5rem;
        right: 0.5rem;
        font-size: 0.7rem;
        font-weight: 600;
        color: #22c55e;
        background: #dcfce7;
        padding: 0.15rem 0.5rem;
        border-radius: 1rem;
    }

    .action-item .btn-complete {
        opacity: 0;
        transition: opacity 0.2s;
    }

    .action-item:hover .btn-complete {
        opacity: 1;
    }

    /* Score goal tracker */
    .score-goal-container {
        margin-top: 0.75rem;
        padding: 0.5rem 0.75rem;
        background: rgba(255, 255, 255, 0.06);
        border-radius: 0.75rem;
        display: flex;
        align-items: center;
        gap: 0.75rem;
        font-size: 0.8rem;
    }

    .score-goal-bar {
        flex: 1;
        height: 6px;
        background: rgba(255, 255, 255, 0.1);
        border-radius: 3px;
        position: relative;
        overflow: visible;
    }

    .score-goal-fill {
        height: 100%;
        border-radius: 3px;
        transition: width 1.5s ease;
    }

    .score-goal-marker {
        position: absolute;
        top: -4px;
        width: 2px;
        height: 14px;
        background: #fff;
        border-radius: 1px;
        transform: translateX(-50%);
    }

    .score-goal-marker::after {
        content: attr(data-goal);
        position: absolute;
        top: -16px;
        left: 50%;
        transform: translateX(-50%);
        font-size: 0.6rem;
        white-space: nowrap;
        opacity: 0.7;
    }

    /* Keyboard shortcut hints */
    .kbd-hint {
        display: none;
        font-size: 0.6rem;
        opacity: 0.5;
        background: rgba(255, 255, 255, 0.1);
        padding: 0.1rem 0.35rem;
        border-radius: 0.25rem;
        margin-left: 0.35rem;
        font-family: monospace;
        vertical-align: middle;
    }

    @media (min-width: 768px) {
        .kbd-hint {
            display: inline;
        }
    }

    /* Share/copy button */
    .btn-share-copied {
        color: #22c55e !important;
        border-color: #22c55e !important;
    }

    /* Lazy section reveal */
    .section-lazy {
        opacity: 0;
        transform: translateY(20px);
        transition: opacity 0.5s ease, transform 0.5s ease;
    }

    .section-lazy.section-visible {
        opacity: 1;
        transform: translateY(0);
    }

    /* Focus visible for accessibility */
    .pillar-card:focus-visible,
    .action-item:focus-visible,
    .quick-action-btn:focus-visible {
        outline: 2px solid #667eea;
        outline-offset: 2px;
        box-shadow: 0 0 0 4px rgba(102, 126, 234, 0.25);
    }

    /* Skip to content link */
    .skip-to-content {
        position: absolute;
        top: -100%;
        left: 0;
        padding: 0.5rem 1rem;
        background: #667eea;
        color: white;
        z-index: 9999;
        border-radius: 0 0 0.5rem 0;
        font-weight: 600;
        transition: top 0.2s;
    }

    .skip-to-content:focus {
        top: 0;
    }

    /* Completed actions counter */
    .completed-counter {
        font-size: 0.7rem;
        padding: 0.15rem 0.5rem;
        border-radius: 1rem;
        background: #dcfce7;
        color: #166534;
        margin-left: 0.5rem;
    }

    /* Toast animation upgrade */
    @keyframes toast-slide-in {
        from {
            transform: translateX(100%);
            opacity: 0;
        }

        to {
            transform: translateX(0);
            opacity: 1;
        }
    }

    /* Score celebration */
    @keyframes score-pulse {

        0%,
        100% {
            transform: scale(1);
        }

        50% {
            transform: scale(1.05);
        }
    }

    .score-celebrate {
        animation: score-pulse 0.6s ease-in-out;
    }

    /* Print styles */
    /* Timing badge */
    .timing-badge {
        display: inline-flex;
        align-items: center;
        gap: 0.3rem;
        font-size: 0.7rem;
        padding: 0.15rem 0.5rem;
        border-radius: 1rem;
        background: rgba(255, 255, 255, 0.12);
        color: rgba(255, 255, 255, 0.7);
        cursor: help;
        transition: background 0.2s;
    }

    .timing-badge:hover {
        background: rgba(255, 255, 255, 0.22);
    }

    .timing-badge .bi {
        font-size: 0.65rem;
    }

    /* Retry toast */
    .retry-toast {
        position: fixed;
        bottom: 2rem;
        left: 50%;
        transform: translateX(-50%);
        z-index: 1060;
        padding: 0.65rem 1.2rem;
        border-radius: 0.75rem;
        background: rgba(33, 37, 41, 0.95);
        color: #fff;
        font-size: 0.85rem;
        display: flex;
        align-items: center;
        gap: 0.5rem;
        box-shadow: 0 4px 20px rgba(0, 0, 0, 0.25);
        animation: slideUpIn 0.3s ease-out;
    }

    @keyframes slideUpIn {
        from {
            opacity: 0;
            transform: translateX(-50%) translateY(1rem);
        }

        to {
            opacity: 1;
            transform: translateX(-50%) translateY(0);
        }
    }

    .retry-toast .spinner-border {
        width: 1rem;
        height: 1rem;
        border-width: 0.15rem;
    }

    /* Export dropdown */
    .export-dropdown {
        position: relative;
        display: inline-block;
    }

    .export-dropdown-menu {
        display: none;
        position: absolute;
        top: 100%;
        left: 0;
        min-width: 10rem;
        background: #fff;
        border-radius: 0.5rem;
        box-shadow: 0 4px 16px rgba(0, 0, 0, 0.15);
        z-index: 1050;
        padding: 0.25rem 0;
        margin-top: 0.25rem;
    }

    .export-dropdown-menu.show {
        display: block;
    }

    .export-dropdown-menu button {
        display: flex;
        align-items: center;
        gap: 0.5rem;
        width: 100%;
        padding: 0.45rem 1rem;
        background: none;
        border: none;
        font-size: 0.85rem;
        color: #333;
        cursor: pointer;
        transition: background 0.15s;
    }

    .export-dropdown-menu button:hover {
        background: #f0f0f0;
    }

    [data-theme="dark"] .export-dropdown-menu {
        background: #2b2b2b;
    }

    [data-theme="dark"] .export-dropdown-menu button {
        color: #ddd;
    }

    [data-theme="dark"] .export-dropdown-menu button:hover {
        background: #3a3a3a;
    }

    /* Offline banner */
    .offline-banner {
        display: none;
        position: fixed;
        top: 0;
        left: 0;
        right: 0;
        z-index: 1080;
        padding: 0.4rem;
        text-align: center;
        font-size: 0.8rem;
        font-weight: 500;
        background: #e74c3c;
        color: #fff;
        animation: slideDown 0.3s ease-out;
    }

    @keyframes slideDown {
        from {
            transform: translateY(-100%);
        }

        to {
            transform: translateY(0);
        }
    }

    .offline-banner.show {
        display: block;
    }

    @media print {
        .health-hero {
            break-inside: avoid;
        }

        .pillar-card {
            break-inside: avoid;
        }

        .action-item {
            break-inside: avoid;
        }

        .btn,
        .sidebar,
        .bottom-nav,
        .navbar,
        .btn-group {
            display: none !important;
        }

        .refresh-timer,
        #quickActionsPanel,
        .auto-refresh-badge {
            display: none !important;
        }

        .card {
            border: 1px solid #ddd !important;
            box-shadow: none !important;
        }

        .health-hero {
            background: #f8f9fa !important;
            color: #333 !important;
        }

        .health-hero * {
            color: #333 !important;
        }

        .section-header .btn {
            display: none !important;
        }

        .score-goal-container,
        .kbd-hint,
        .btn-complete {
            display: none !important;
        }

        .section-lazy {
            opacity: 1 !important;
            transform: none !important;
        }

        .timing-badge,
        .retry-toast,
        .offline-banner,
        .export-dropdown-menu {
            display: none !important;
        }

        body {
            font-size: 11pt;
        }
    }

    /* Tablet breakpoint - fix orphaned 5th pillar card */
    @media (min-width: 768px) and (max-width: 1199px) {
        #pillarCards .col-sm-6 {
            flex: 0 0 33.333%;
            max-width: 33.333%;
        }
    }

    /* Loading shimmer */
    @keyframes shimmer {
        0% {
            background-position: -200% 0;
        }

        100% {
            background-position: 200% 0;
        }
    }

    /* Loading progress bar */
    .health-loading-bar {
        position: absolute;
        bottom: 0;
        left: 0;
        right: 0;
        height: 3px;
        background: rgba(255, 255, 255, 0.1);
        overflow: hidden;
        border-radius: 0 0 1.25rem 1.25rem;
    }

    .health-loading-bar .bar {
        height: 100%;
        background: linear-gradient(90deg, #667eea, #22c55e, #667eea);
        background-size: 200% 100%;
        animation: shimmer 1.2s ease-in-out infinite;
        transition: width 0.5s ease;
    }

    /* Trend sparkline */
    .sparkline-container {
        display: inline-flex;
        align-items: center;
        gap: 0.5rem;
        background: rgba(255, 255, 255, 0.08);
        border-radius: 0.75rem;
        padding: 0.5rem 0.75rem;
        margin-top: 0.5rem;
    }

    .sparkline-container canvas {
        width: 120px !important;
        height: 35px !important;
    }

    .trend-badge {
        font-size: 0.75rem;
        font-weight: 600;
        display: inline-flex;
        align-items: center;
        gap: 0.25rem;
        padding: 0.15rem 0.5rem;
        border-radius: 1rem;
    }

    .trend-badge.trend-up {
        background: rgba(34, 197, 94, 0.15);
        color: #22c55e;
    }

    .trend-badge.trend-down {
        background: rgba(239, 68, 68, 0.15);
        color: #ef4444;
    }

    .trend-badge.trend-neutral {
        background: rgba(148, 163, 184, 0.15);
        color: #94a3b8;
    }

    /* Pillar trend indicator */
    .pillar-trend {
        font-size: 0.65rem;
        font-weight: 600;
        display: inline-flex;
        align-items: center;
        gap: 0.15rem;
        margin-left: 0.25rem;
    }

    /* Auto-refresh timer */
    .refresh-timer {
        display: inline-flex;
        align-items: center;
        gap: 0.35rem;
        font-size: 0.75rem;
        opacity: 0.6;
        cursor: pointer;
        transition: opacity 0.2s;
    }

    .refresh-timer:hover {
        opacity: 1;
    }

    .refresh-timer .timer-dot {
        width: 6px;
        height: 6px;
        border-radius: 50%;
        background: #22c55e;
        animation: pulse-dot 2s ease-in-out infinite;
    }

    @keyframes pulse-dot {

        0%,
        100% {
            opacity: 0.4;
        }

        50% {
            opacity: 1;
        }
    }

    /* Dark mode hero */
    [data-theme="dark"] .health-hero {
        background: linear-gradient(135deg, #0f172a 0%, #1e1b4b 50%, #1e293b 100%);
    }

    [data-theme="dark"] .skeleton {
        background: linear-gradient(90deg, #334155 25%, #475569 50%, #334155 75%);
        background-size: 200% 100%;
        animation: shimmer 1.5s ease-in-out infinite;
    }

    [data-theme="dark"] .pillar-card {
        background: #1e293b;
        border: 1px solid #334155;
    }

    [data-theme="dark"] .card {
        background: #1e293b;
        border-color: #334155;
    }

    [data-theme="dark"] .rep-metric-card {
        background: #0f172a;
        border-color: #334155;
    }

    [data-theme="dark"] .funnel-metric {
        background: #0f172a;
        border-color: #334155;
    }

    [data-theme="dark"] .action-item {
        border-color: #334155;
    }

    /* Mobile improvements */
    @media (max-width: 576px) {
        .health-hero {
            padding: 1.5rem;
        }

        .score-circle {
            width: 140px;
            height: 140px;
        }

        .score-value {
            font-size: 2.25rem;
        }

        .health-hero h2 {
            font-size: 1.25rem;
            margin-top: 1rem;
        }

        .sparkline-container {
            justify-content: center;
        }

        /* Better button layout on mobile */
        .health-hero .d-flex.flex-wrap.gap-2 {
            justify-content: center;
        }

        .health-hero .btn-sm {
            flex: 1 1 auto;
            min-width: 0;
            font-size: 0.78rem;
            padding: 0.35rem 0.6rem;
        }

        .kbd-hint {
            display: none;
        }

        /* Export dropdown - full width on mobile */
        .export-dropdown {
            flex: 1 1 auto;
        }

        .export-dropdown .btn {
            width: 100%;
        }

        .export-dropdown-menu {
            left: 0;
            right: 0;
        }

        /* Timing badge - smaller */
        .timing-badge {
            font-size: 0.6rem;
            padding: 0.1rem 0.35rem;
        }

        /* Score goal - stack vertically */
        .score-goal-container {
            flex-wrap: wrap;
            justify-content: center;
        }

        /* Action items - tighter spacing */
        .action-item {
            padding: 0.75rem;
        }

        .action-item .d-flex {
            flex-wrap: wrap;
            gap: 0.3rem;
        }

        /* Pillar cards - single column */
        #pillarCards .col-sm-6 {
            flex: 0 0 100%;
            max-width: 100%;
        }

        /* Smaller section headings */
        .section-header h5 {
            font-size: 0.95rem;
        }

        .section-header .badge {
            font-size: 0.65rem;
        }

        /* Offline banner - safe area */
        .offline-banner {
            padding-top: env(safe-area-inset-top, 0.4rem);
        }
    }

    /* Quick Actions bar */
    .quick-actions {
        display: flex;
        flex-wrap: wrap;
        gap: 0.5rem;
    }

    .quick-action-btn {
        display: inline-flex;
        align-items: center;
        gap: 0.5rem;
        padding: 0.5rem 1rem;
        border-radius: 0.75rem;
        font-size: 0.85rem;
        font-weight: 500;
        text-decoration: none;
        transition: all 0.2s ease;
        border: 1px solid var(--bs-border-color);
        background: var(--bs-body-bg);
        color: var(--bs-body-color);
    }

    .quick-action-btn:hover {
        transform: translateY(-2px);
        box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
    }

    .quick-action-btn .qa-icon {
        width: 32px;
        height: 32px;
        border-radius: 0.5rem;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 1rem;
    }

    .quick-action-btn .qa-badge {
        font-size: 0.7rem;
        padding: 0.15rem 0.4rem;
        border-radius: 1rem;
        font-weight: 700;
        margin-left: auto;
    }

    /* Operation panel */
    .ops-metric {
        text-align: center;
        padding: 0.75rem;
        border-radius: 0.75rem;
        background: var(--bs-light, #f8f9fa);
    }

    .ops-metric-value {
        font-size: 1.5rem;
        font-weight: 700;
        line-height: 1.2;
    }

    .ops-metric-label {
        font-size: 0.75rem;
        color: #6b7280;
        margin-top: 0.25rem;
    }

    .ops-status-row {
        display: flex;
        align-items: center;
        gap: 0.75rem;
        padding: 0.75rem;
        border-radius: 0.75rem;
        margin-bottom: 0.5rem;
        border: 1px solid var(--bs-border-color);
    }

    .ops-status-icon {
        width: 40px;
        height: 40px;
        border-radius: 0.5rem;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 1.1rem;
        flex-shrink: 0;
    }

    /* Loading progress steps */
    .loading-steps {
        display: flex;
        align-items: center;
        gap: 0.5rem;
        font-size: 0.8rem;
        opacity: 0.7;
        margin-top: 0.5rem;
    }

    .loading-step {
        display: inline-flex;
        align-items: center;
        gap: 0.25rem;
    }

    .loading-step.done {
        color: #22c55e;
    }

    .loading-step.active {
        color: #f59e0b;
    }

    .loading-step.pending {
        color: #94a3b8;
    }

    [data-theme="dark"] .ops-metric {
        background: #0f172a;
    }

    [data-theme="dark"] .quick-action-btn {
        background: #1e293b;
        border-color: #334155;
    }

    [data-theme="dark"] .action-item.completed::after {
        background: rgba(34, 197, 94, 0.15);
        color: #86efac;
    }

    [data-theme="dark"] .completed-counter {
        background: rgba(34, 197, 94, 0.15);
        color: #86efac;
    }

    [data-theme="dark"] .score-goal-container {
        background: rgba(255, 255, 255, 0.04);
    }

    /* =============================================
   SCORE DELTAS (badges +/- nos pilares)
   ============================================= */
    .pillar-delta {
        display: inline-flex;
        align-items: center;
        font-size: 0.7rem;
        font-weight: 600;
        padding: 0.1rem 0.4rem;
        border-radius: 0.5rem;
        gap: 0.15rem;
        line-height: 1;
    }

    .pillar-delta.delta-up {
        background: rgba(34, 197, 94, 0.15);
        color: #22c55e;
    }

    .pillar-delta.delta-down {
        background: rgba(239, 68, 68, 0.15);
        color: #ef4444;
    }

    .pillar-delta.delta-same {
        background: rgba(156, 163, 175, 0.15);
        color: #9ca3af;
    }

    .hero-delta {
        font-size: 0.85rem;
        font-weight: 600;
        margin-top: 0.25rem;
        z-index: 1;
    }

    .hero-delta.delta-up {
        color: #22c55e;
    }

    .hero-delta.delta-down {
        color: #ef4444;
    }

    /* =============================================
   WEEKLY PLAN (plano de ação semanal)
   ============================================= */
    .weekly-plan-card {
        background: linear-gradient(135deg, #f0f9ff 0%, #e0f2fe 100%);
        border: 2px solid #bae6fd;
        border-radius: 1rem;
        overflow: hidden;
    }

    [data-theme="dark"] .weekly-plan-card {
        background: linear-gradient(135deg, #1e293b 0%, #0f172a 100%);
        border-color: #334155;
    }

    .weekly-plan-header {
        background: linear-gradient(90deg, #0284c7, #0369a1);
        padding: 1rem 1.25rem;
        color: #fff;
    }

    .weekly-plan-action {
        display: flex;
        align-items: flex-start;
        gap: 0.75rem;
        padding: 0.85rem;
        border-bottom: 1px solid rgba(0, 0, 0, 0.06);
        transition: background 0.15s;
    }

    .weekly-plan-action:hover {
        background: rgba(0, 0, 0, 0.02);
    }

    [data-theme="dark"] .weekly-plan-action {
        border-color: rgba(255, 255, 255, 0.06);
    }

    [data-theme="dark"] .weekly-plan-action:hover {
        background: rgba(255, 255, 255, 0.03);
    }

    .weekly-plan-number {
        width: 28px;
        height: 28px;
        border-radius: 50%;
        background: #0284c7;
        color: #fff;
        display: flex;
        align-items: center;
        justify-content: center;
        font-weight: 700;
        font-size: 0.8rem;
        flex-shrink: 0;
    }

    .weekly-plan-gain {
        text-align: center;
        padding: 0.85rem;
        background: rgba(34, 197, 94, 0.08);
        border-radius: 0 0 1rem 1rem;
    }

    /* =============================================
   CATEGORY BREAKDOWN (análise por categoria)
   ============================================= */
    .category-row {
        display: flex;
        align-items: center;
        gap: 0.75rem;
        padding: 0.6rem 0;
        border-bottom: 1px solid rgba(0, 0, 0, 0.05);
    }

    .category-row:last-child {
        border-bottom: none;
    }

    [data-theme="dark"] .category-row {
        border-color: rgba(255, 255, 255, 0.06);
    }

    .category-issue-bar {
        width: 80px;
        height: 6px;
        background: #e5e7eb;
        border-radius: 3px;
        overflow: hidden;
    }

    .category-issue-bar .fill {
        height: 100%;
        border-radius: 3px;
        transition: width 0.6s ease;
    }

    /* =============================================
   PAUSED RECOVERY (recuperação de pausados)
   ============================================= */
    .recovery-card {
        background: linear-gradient(135deg, #fffbeb 0%, #fef3c7 100%);
        border: 2px solid #fcd34d;
        border-radius: 1rem;
    }

    [data-theme="dark"] .recovery-card {
        background: linear-gradient(135deg, #1c1917 0%, #292524 100%);
        border-color: #78716c;
    }

    .recovery-item {
        display: flex;
        align-items: center;
        gap: 0.75rem;
        padding: 0.5rem 0;
        border-bottom: 1px solid rgba(0, 0, 0, 0.05);
    }

    .recovery-item:last-child {
        border-bottom: none;
    }

    .recovery-badge {
        font-size: 0.7rem;
        padding: 0.15rem 0.5rem;
        border-radius: 0.5rem;
        font-weight: 600;
    }

    .recovery-badge.reactivate {
        background: #dcfce7;
        color: #166534;
    }

    .recovery-badge.optimize {
        background: #fef9c3;
        color: #854d0e;
    }

    .recovery-badge.restock {
        background: #fee2e2;
        color: #991b1b;
    }

    .recovery-badge.review {
        background: #e5e7eb;
        color: #374151;
    }

    [data-theme="dark"] .recovery-badge.reactivate {
        background: rgba(34, 197, 94, 0.2);
        color: #86efac;
    }

    [data-theme="dark"] .recovery-badge.optimize {
        background: rgba(245, 158, 11, 0.2);
        color: #fcd34d;
    }

    [data-theme="dark"] .recovery-badge.restock {
        background: rgba(239, 68, 68, 0.2);
        color: #fca5a5;
    }

    [data-theme="dark"] .recovery-badge.review {
        background: rgba(156, 163, 175, 0.2);
        color: #d1d5db;
    }

    /* =============================================
   HISTORY CHART MODAL (gráfico expandido)
   ============================================= */
    .history-chart-container {
        height: 300px;
        position: relative;
    }

    .history-legend {
        display: flex;
        flex-wrap: wrap;
        gap: 0.75rem;
        justify-content: center;
        padding-top: 0.5rem;
    }

    .history-legend-item {
        display: flex;
        align-items: center;
        gap: 0.3rem;
        font-size: 0.75rem;
    }

    .history-legend-dot {
        width: 10px;
        height: 10px;
        border-radius: 50%;
    }
</style>

<!-- Offline banner -->
<div class="offline-banner" id="offlineBanner" role="alert">
    <i class="bi bi-wifi-off me-1"></i>Sem conexão — dados em cache serão exibidos
</div>

<!-- Skip to content (accessibility) -->
<a href="#healthHero" class="skip-to-content">Pular para o diagnóstico</a>

<!-- Hero Score Section -->
<div class="health-hero" id="healthHero" role="region" aria-label="Score geral da conta">
    <div class="health-loading-bar" id="heroLoadingBar">
        <div class="bar" id="heroLoadingBarFill" style="width: 15%;"></div>
    </div>
    <div class="row align-items-center">
        <div class="col-md-4 text-center">
            <div class="score-circle score-warning" id="scoreCircle" role="img" aria-label="Score geral da conta">
                <svg viewBox="0 0 180 180" aria-hidden="true">
                    <circle class="score-bg" cx="90" cy="90" r="80" />
                    <circle class="score-fill" cx="90" cy="90" r="80"
                        stroke-dasharray="502.65"
                        stroke-dashoffset="502.65"
                        id="scoreFill" />
                </svg>
                <div class="score-value" id="scoreValue">--</div>
                <div class="score-label" id="scoreLabel">Carregando...</div>
            </div>
            <!-- Sparkline de tendência -->
            <div class="sparkline-container" id="sparklineContainer" style="display:none;">
                <canvas id="trendSparkline"></canvas>
                <span class="trend-badge trend-neutral" id="trendBadge">
                    <i class="bi bi-dash"></i> <span id="trendValue">--</span>
                </span>
            </div>
        </div>
        <div class="col-md-8">
            <div class="d-flex align-items-center gap-2 mb-2">
                <h2 class="fw-bold mb-0">Diagnóstico da Conta</h2>
                <div class="refresh-timer" id="refreshTimer" data-action="refresh-diagnostic" title="Clique para atualizar">
                    <span class="timer-dot"></span>
                    <span id="refreshTimerText">agora</span>
                    <span class="auto-refresh-badge" id="autoRefreshBadge" title="Atualização automática a cada 15 min">
                        <i class="bi bi-arrow-repeat"></i>
                    </span>
                </div>
                <!-- Data Quality Badge -->
                <div id="dataQualityBadge" class="data-quality-badge" style="display: none;" role="status"></div>
            </div>
            <p class="opacity-75 mb-3" id="mainRecommendation">Analisando sua conta...</p>
            <div class="row" id="summaryStats">
                <div class="col-4 col-md-2 summary-stat">
                    <div class="stat-value text-danger" id="statCritical">-</div>
                    <div class="stat-label">Críticos</div>
                </div>
                <div class="col-4 col-md-2 summary-stat">
                    <div class="stat-value text-warning" id="statWarning">-</div>
                    <div class="stat-label">Alertas</div>
                </div>
                <div class="col-4 col-md-2 summary-stat">
                    <div class="stat-value text-info" id="statActions">-</div>
                    <div class="stat-label">Ações</div>
                </div>
                <div class="col-6 col-md-3 summary-stat hero-stat-item d-none">
                    <div class="stat-value text-light small" id="statWorstPillar">-</div>
                    <div class="stat-label">Pior Pilar</div>
                </div>
                <div class="col-6 col-md-3 summary-stat hero-stat-item d-none">
                    <div class="stat-value text-success" id="statPotentialGain">-</div>
                    <div class="stat-label">Ganho Potencial</div>
                </div>
            </div>
            <div class="d-flex flex-wrap gap-2 mt-2">
                <button class="btn btn-outline-light btn-sm" data-action="refresh-diagnostic" id="btnRefresh" aria-label="Atualizar diagnóstico">
                    <i class="bi bi-arrow-clockwise me-1"></i>Atualizar<span class="kbd-hint">R</span>
                </button>
                <div class="export-dropdown" id="exportDropdown">
                    <button class="btn btn-outline-light btn-sm" data-action="toggle-export-menu" id="btnExport" aria-label="Exportar diagnóstico" aria-haspopup="true" aria-expanded="false">
                        <i class="bi bi-download me-1"></i>Exportar<span class="kbd-hint">E</span>
                    </button>
                    <div class="export-dropdown-menu" id="exportMenu" role="menu">
                        <button role="menuitem" data-action="export-diagnostic-txt"><i class="bi bi-file-text"></i> Relatório TXT</button>
                        <button role="menuitem" data-action="export-actions-csv"><i class="bi bi-filetype-csv"></i> Ações CSV</button>
                        <button role="menuitem" data-action="export-json"><i class="bi bi-filetype-json"></i> Dados JSON</button>
                    </div>
                </div>
                <button class="btn btn-outline-light btn-sm" data-action="share-diagnostic" id="btnShare" aria-label="Compartilhar diagnóstico">
                    <i class="bi bi-share me-1"></i>Compartilhar<span class="kbd-hint">S</span>
                </button>
                <button class="btn btn-outline-light btn-sm" data-action="print-diagnostic" aria-label="Imprimir diagnóstico">
                    <i class="bi bi-printer me-1"></i>Imprimir<span class="kbd-hint">P</span>
                </button>
                <span class="timing-badge" id="timingBadge" style="display:none;" title="Tempo de geração do diagnóstico">
                    <i class="bi bi-speedometer2"></i><span id="timingValue"></span>
                </span>
            </div>
            <!-- Score goal tracker -->
            <div class="score-goal-container" id="scoreGoalContainer" style="display:none;">
                <span class="opacity-75"><i class="bi bi-bullseye me-1"></i>Meta:</span>
                <div class="score-goal-bar">
                    <div class="score-goal-fill" id="scoreGoalFill"></div>
                    <div class="score-goal-marker" id="scoreGoalMarker"></div>
                </div>
                <span class="fw-bold" id="scoreGoalText">--</span>
                <button class="btn btn-link btn-sm text-white p-0 opacity-50" data-action="set-score-goal" title="Alterar meta" aria-label="Definir meta de score">
                    <i class="bi bi-pencil-square"></i>
                </button>
            </div>
            <small class="d-block mt-1 opacity-50" id="generatedAt"></small>
            <!-- Loading progress steps -->
            <div class="loading-steps" id="loadingSteps">
                <span class="loading-step active" id="stepRep"><i class="bi bi-circle-fill" style="font-size:0.5rem"></i> Reputação</span>
                <span class="loading-step pending" id="stepSeo"><i class="bi bi-circle-fill" style="font-size:0.5rem"></i> SEO</span>
                <span class="loading-step pending" id="stepComp"><i class="bi bi-circle-fill" style="font-size:0.5rem"></i> Preços</span>
                <span class="loading-step pending" id="stepOps"><i class="bi bi-circle-fill" style="font-size:0.5rem"></i> Operação</span>
                <span class="loading-step pending" id="stepSales"><i class="bi bi-circle-fill" style="font-size:0.5rem"></i> Vendas</span>
            </div>
        </div>
    </div>
</div>

<!-- 5 Pillar Cards -->
<div class="row g-3 mb-4" id="pillarCards" role="region" aria-label="Pilares de saúde da conta">
    <!-- Skeleton placeholders -->
    <?php for ($i = 0; $i < 5; $i++): ?>
        <div class="col-12 col-sm-6 col-lg">
            <div class="card pillar-card h-100">
                <div class="card-body">
                    <div class="d-flex align-items-center gap-3 mb-2">
                        <div class="skeleton skeleton-circle" style="width:48px;height:48px;"></div>
                        <div style="flex:1">
                            <div class="skeleton" style="width:80%;height:14px;margin-bottom:6px;"></div>
                            <div class="skeleton" style="width:50%;height:24px;"></div>
                        </div>
                    </div>
                    <div class="pillar-score-bar">
                        <div class="skeleton" style="width:60%;height:100%;"></div>
                    </div>
                </div>
            </div>
        </div>
    <?php endfor; ?>
</div>

<!-- Weekly Plan (Plano de Ação Semanal) -->
<div class="weekly-plan-card mb-4 section-lazy" id="weeklyPlanSection" style="display:none;" role="region" aria-label="Plano de ação semanal">
    <div class="weekly-plan-header d-flex align-items-center justify-content-between">
        <div>
            <h5 class="mb-0"><i class="bi bi-calendar-check me-2"></i>Foco da Semana</h5>
            <small class="opacity-75">Top 3 ações de maior impacto</small>
        </div>
        <div class="text-end">
            <span class="badge bg-light text-dark" id="weeklyPlanGain"></span>
        </div>
    </div>
    <div id="weeklyPlanActions"></div>
    <div class="weekly-plan-gain" id="weeklyPlanFooter"></div>
</div>

<!-- Quick Actions -->
<div class="card border-0 shadow-sm mb-4" id="quickActionsSection" style="display:none;">
    <div class="card-body">
        <div class="section-header">
            <h5>
                <i class="bi bi-rocket-takeoff text-primary"></i>
                Ações Rápidas
            </h5>
        </div>
        <div class="quick-actions" id="quickActionsList"></div>
    </div>
</div>

<!-- Action Items -->
<div class="card border-0 shadow-sm mb-4 section-lazy" id="actionItemsSection" role="region" aria-label="Ações recomendadas">
    <div class="card-body">
        <div class="section-header">
            <h5>
                <i class="bi bi-lightning-charge-fill text-warning"></i>
                O Que Fazer Agora
                <span class="section-count" id="actionCount">0</span>
                <span class="completed-counter" id="completedCounter" style="display:none;"></span>
            </h5>
            <div class="btn-group btn-group-sm">
                <button class="btn btn-outline-secondary active" data-action="filter-actions" data-filter="all">Todos</button>
                <button class="btn btn-outline-danger" data-action="filter-actions" data-filter="critical">Críticos</button>
                <button class="btn btn-outline-warning" data-action="filter-actions" data-filter="warning">Alertas</button>
            </div>
        </div>
        <div id="actionItemsList">
            <div class="text-center py-4 text-muted">
                <div class="spinner-border spinner-border-sm me-2" role="status"></div>
                Analisando...
            </div>
        </div>
    </div>
</div>

<!-- Reputation Detail Panel -->
<div class="card border-0 shadow-sm mb-4 section-lazy" id="reputationSection" style="display:none;" role="region" aria-label="Detalhes da reputação">
    <div class="card-body">
        <div class="section-header">
            <h5>
                <i class="bi bi-star-fill text-warning"></i>
                Detalhes da Reputação
            </h5>
            <a href="https://www.mercadolivre.com.br/reputacao" target="_blank" class="btn btn-sm btn-outline-primary">
                <i class="bi bi-box-arrow-up-right me-1"></i>Ver no ML
            </a>
        </div>
        <div class="row g-3">
            <div class="col-md-4 text-center">
                <div id="repLevelBadge" class="rep-level-badge rep-level-3 mb-2">Carregando...</div>
                <div id="repPowerSeller" class="small text-muted"></div>
                <div class="ratings-donut mt-3">
                    <canvas id="ratingsChart"></canvas>
                    <div class="ratings-center">
                        <div class="fw-bold" id="ratingsPositivePct">--</div>
                        <div class="text-muted" style="font-size:0.65rem;">POSITIVAS</div>
                    </div>
                </div>
            </div>
            <div class="col-md-8">
                <div class="row g-2">
                    <div class="col-6">
                        <div class="rep-metric-card">
                            <div class="rep-metric-value text-success" id="repSalesTotal">0</div>
                            <div class="rep-metric-label">Vendas Concluídas</div>
                        </div>
                    </div>
                    <div class="col-6">
                        <div class="rep-metric-card">
                            <div class="rep-metric-value" id="repCancelledTotal">0</div>
                            <div class="rep-metric-label">Cancelamentos</div>
                        </div>
                    </div>
                    <div class="col-4">
                        <div class="rep-metric-card">
                            <div class="rep-metric-value" id="repClaimsRate">0%</div>
                            <div class="rep-metric-label">Reclamações</div>
                        </div>
                    </div>
                    <div class="col-4">
                        <div class="rep-metric-card">
                            <div class="rep-metric-value" id="repCancelRate">0%</div>
                            <div class="rep-metric-label">Cancelamentos</div>
                        </div>
                    </div>
                    <div class="col-4">
                        <div class="rep-metric-card">
                            <div class="rep-metric-value" id="repDelayRate">0%</div>
                            <div class="rep-metric-label">Atrasos</div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Two Column: Items Attention + Price Analysis -->
<div class="row g-4 mb-4 section-lazy">
    <!-- Items needing attention -->
    <div class="col-lg-6">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-body">
                <div class="section-header">
                    <h5>
                        <i class="bi bi-exclamation-diamond text-danger"></i>
                        Anúncios com Problemas
                    </h5>
                </div>
                <div id="worstItemsList">
                    <div class="text-center py-4 text-muted">
                        <div class="spinner-border spinner-border-sm me-2" role="status"></div>
                        Carregando...
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Price competitiveness -->
    <div class="col-lg-6">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-body">
                <div class="section-header">
                    <h5>
                        <i class="bi bi-tag text-primary"></i>
                        Análise de Preços
                    </h5>
                </div>
                <div id="priceAnalysisList">
                    <div class="text-center py-4 text-muted">
                        <div class="spinner-border spinner-border-sm me-2" role="status"></div>
                        Carregando...
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Items needing attention alerts -->
<div class="card border-0 shadow-sm mb-4" id="attentionSection" style="display:none;">
    <div class="card-body">
        <div class="section-header">
            <h5>
                <i class="bi bi-bell text-info"></i>
                Itens que Precisam de Atenção
            </h5>
        </div>
        <div id="attentionList"></div>
    </div>
</div>

<!-- Stale Listings (Anúncios Parados) -->
<div class="card border-0 shadow-sm mb-4 section-lazy" id="staleSection" style="display:none;" role="region" aria-label="Anúncios parados">
    <div class="card-body">
        <div class="section-header">
            <h5>
                <i class="bi bi-hourglass-bottom text-danger"></i>
                Anúncios Parados
            </h5>
            <span class="badge bg-danger" id="staleCountBadge">0</span>
        </div>

        <!-- Impact summary -->
        <div id="staleImpactAlert" class="alert alert-danger d-flex align-items-start gap-2 mb-3" style="display:none !important;">
            <i class="bi bi-exclamation-triangle-fill mt-1"></i>
            <div>
                <strong id="staleImpactTitle">Impacto na conta</strong>
                <p class="mb-0 small" id="staleImpactText"></p>
            </div>
        </div>

        <!-- Summary metrics -->
        <div class="row g-3 mb-3" id="staleSummaryRow">
            <div class="col-6 col-md-3">
                <div class="funnel-metric">
                    <div class="funnel-value text-danger" id="staleTotalCount">0</div>
                    <div class="funnel-label">Parados</div>
                </div>
            </div>
            <div class="col-6 col-md-3">
                <div class="funnel-metric">
                    <div class="funnel-value text-warning" id="stalePercent">0%</div>
                    <div class="funnel-label">do Catálogo</div>
                </div>
            </div>
            <div class="col-6 col-md-3">
                <div class="funnel-metric">
                    <div class="funnel-value text-danger" id="staleCriticalCount">0</div>
                    <div class="funnel-label">Críticos (90d+)</div>
                </div>
            </div>
            <div class="col-6 col-md-3">
                <div class="funnel-metric">
                    <div class="funnel-value text-info" id="staleFrozenValue">R$ 0</div>
                    <div class="funnel-label">Valor Parado</div>
                </div>
            </div>
        </div>

        <!-- Stale cause diagnosis -->
        <div id="staleCausesRow" class="mb-3" style="display:none;"></div>

        <!-- Stale items list -->
        <div id="staleItemsList" class="stale-items-list"></div>

        <!-- Show more toggle -->
        <div class="text-center mt-3" id="staleShowMoreWrap" style="display:none;">
            <button class="btn btn-sm btn-outline-secondary" data-action="toggle-stale-items">
                <i class="bi bi-chevron-down me-1"></i>
                <span id="staleShowMoreText">Ver mais</span>
            </button>
        </div>
    </div>
</div>

<!-- Category Breakdown (Análise por Categoria) -->
<div class="card border-0 shadow-sm mb-4 section-lazy" id="categoryBreakdownSection" style="display:none;" role="region" aria-label="Análise por categoria">
    <div class="card-body">
        <div class="section-header">
            <h5>
                <i class="bi bi-grid-3x3-gap-fill text-info"></i>
                Análise por Categoria
                <span class="section-count" id="categoryCount">0</span>
            </h5>
        </div>
        <p class="text-muted small mb-3">Categorias com mais problemas — foque nas que mais impactam suas vendas</p>
        <div id="categoryBreakdownList"></div>
    </div>
</div>

<!-- Paused Items Recovery (Recuperação de Pausados) -->
<div class="recovery-card mb-4 section-lazy" id="pausedRecoverySection" style="display:none;" role="region" aria-label="Recuperação de pausados">
    <div class="card-body p-4">
        <div class="d-flex align-items-start justify-content-between mb-3">
            <div>
                <h5 class="mb-1"><i class="bi bi-arrow-repeat text-warning me-2"></i>Oportunidade de Recuperação</h5>
                <p class="text-muted small mb-0">Itens pausados com potencial de receita</p>
            </div>
            <div class="text-end">
                <div class="fw-bold fs-5 text-success" id="recoveryTotalValue">R$ 0</div>
                <small class="text-muted">receita mensal potencial</small>
            </div>
        </div>
        <div class="row g-3 mb-3">
            <div class="col-4 text-center">
                <div class="fw-bold fs-5" id="recoveryTotalPaused">0</div>
                <small class="text-muted">Pausados</small>
            </div>
            <div class="col-4 text-center">
                <div class="fw-bold fs-5 text-success" id="recoveryReactivatable">0</div>
                <small class="text-muted">Reativáveis</small>
            </div>
            <div class="col-4 text-center">
                <div class="fw-bold fs-5 text-danger" id="recoveryNeedsRestock">0</div>
                <small class="text-muted">Sem estoque</small>
            </div>
        </div>
        <div id="recoveryItemsList"></div>
    </div>
</div>

<!-- Sales & Conversion Dashboard -->
<div class="card border-0 shadow-sm mb-4 section-lazy" id="salesSection" style="display:none;" role="region" aria-label="Vendas e conversão">
    <div class="card-body">
        <div class="section-header">
            <h5>
                <i class="bi bi-graph-up text-success"></i>
                Vendas & Conversão (30 dias)
            </h5>
            <a href="/dashboard/analytics" class="btn btn-sm btn-outline-primary">
                <i class="bi bi-bar-chart-line me-1"></i>Analytics Completo
            </a>
        </div>
        <!-- Funnel metrics -->
        <div class="row g-3 mb-4">
            <div class="col-6 col-md-3">
                <div class="funnel-metric">
                    <div class="funnel-value" id="salesVisits30d">0</div>
                    <div class="funnel-label">Visitas</div>
                </div>
            </div>
            <div class="col-6 col-md-3">
                <div class="funnel-metric">
                    <div class="funnel-value" id="salesCount30d">0</div>
                    <div class="funnel-label">Vendas</div>
                    <div class="funnel-compare" id="salesCount30dCompare"></div>
                </div>
            </div>
            <div class="col-6 col-md-3">
                <div class="funnel-metric">
                    <div class="funnel-value text-success" id="revenue30d">R$ 0</div>
                    <div class="funnel-label">Receita</div>
                    <div class="funnel-compare" id="revenue30dCompare"></div>
                </div>
            </div>
            <div class="col-6 col-md-3">
                <div class="funnel-metric">
                    <div class="funnel-value" id="avgTicket">R$ 0</div>
                    <div class="funnel-label">Ticket Médio</div>
                    <div class="funnel-compare" id="avgTicketCompare"></div>
                </div>
            </div>
        </div>
        <!-- Revenue per item -->
        <div class="row g-2 mb-3">
            <div class="col-6">
                <div class="funnel-metric">
                    <div class="funnel-value small" id="revenuePerItem">R$ 0</div>
                    <div class="funnel-label">Receita / Anúncio Ativo</div>
                </div>
            </div>
        </div>
        <!-- Charts: Vendas e Receita lado a lado -->
        <div class="row g-3">
            <div class="col-md-4">
                <div class="text-center small text-muted mb-1">Vendas (unidades)</div>
                <div style="position:relative;height:200px;">
                    <canvas id="salesUnitsChart"></canvas>
                </div>
            </div>
            <div class="col-md-4">
                <div class="text-center small text-muted mb-1">Receita (R$)</div>
                <div style="position:relative;height:200px;">
                    <canvas id="salesRevenueChart"></canvas>
                </div>
            </div>
            <div class="col-md-4">
                <div class="d-flex flex-column gap-3">
                    <div class="funnel-metric">
                        <div class="funnel-value" id="salesGrowth">0%</div>
                        <div class="funnel-label">Crescimento Vendas</div>
                    </div>
                    <div class="funnel-metric">
                        <div class="funnel-value" id="conversionRate">0%</div>
                        <div class="funnel-label">Taxa de Conversão</div>
                    </div>
                    <div class="funnel-metric">
                        <div class="funnel-value" id="revenueGrowth">0%</div>
                        <div class="funnel-label">Crescimento Receita</div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Operation Dashboard -->
<div class="card border-0 shadow-sm mb-4 section-lazy" id="operationSection" style="display:none;" role="region" aria-label="Operação e logística">
    <div class="card-body">
        <div class="section-header">
            <h5>
                <i class="bi bi-truck text-info"></i>
                Operação & Logística
            </h5>
            <a href="/dashboard/orders" class="btn btn-sm btn-outline-primary">
                <i class="bi bi-box-seam me-1"></i>Ver Pedidos
            </a>
        </div>
        <div class="row g-3 mb-3">
            <div class="col-6 col-md-2">
                <div class="ops-metric">
                    <div class="ops-metric-value" id="opsOrders30d">0</div>
                    <div class="ops-metric-label">Pedidos (30d)</div>
                </div>
            </div>
            <div class="col-6 col-md-2">
                <div class="ops-metric">
                    <div class="ops-metric-value" id="opsCancelled30d">0</div>
                    <div class="ops-metric-label">Cancelados</div>
                </div>
            </div>
            <div class="col-6 col-md-2">
                <div class="ops-metric">
                    <div class="ops-metric-value" id="opsDelayRate">0%</div>
                    <div class="ops-metric-label">Taxa de Atraso</div>
                </div>
            </div>
            <div class="col-6 col-md-2">
                <div class="ops-metric">
                    <div class="ops-metric-value" id="opsUnanswered">0</div>
                    <div class="ops-metric-label">Perguntas s/ Resp.</div>
                </div>
            </div>
            <div class="col-6 col-md-2">
                <div class="ops-metric">
                    <div class="ops-metric-value" id="opsOpenClaims">0</div>
                    <div class="ops-metric-label">Mediações Abertas</div>
                </div>
            </div>
            <div class="col-6 col-md-2">
                <div class="ops-metric">
                    <div class="ops-metric-value" id="opsResponseTime">-</div>
                    <div class="ops-metric-label">Tempo Resp. Médio</div>
                </div>
            </div>
        </div>
        <div id="opsStatusList"></div>
    </div>
</div>

<!-- Data Sources Transparency -->
<div class="card border-0 shadow-sm mb-4 section-lazy" id="crossInsightsSection" style="display:none;" role="region" aria-label="Insights cruzados">
    <div class="card-body">
        <div class="section-header">
            <h5>
                <i class="bi bi-lightbulb text-warning"></i>
                Insights Cruzados
            </h5>
            <span class="badge bg-warning-subtle text-warning" id="insightsCount">0</span>
        </div>
        <p class="text-muted small mb-3">Correlações entre pilares que revelam oportunidades ocultas.</p>
        <div id="crossInsightsList"></div>
    </div>
</div>

<div class="card border-0 shadow-sm mb-4 section-lazy" id="dataSourcesSection" style="display:none;" role="region" aria-label="Fontes de dados">
    <div class="card-body">
        <div class="section-header">
            <h5>
                <i class="bi bi-shield-check text-success"></i>
                Fontes de Dados
            </h5>
            <span class="badge bg-success-subtle text-success" id="dataSourcesBadge">0 sinais ML</span>
        </div>
        <p class="text-muted small mb-3">Transparência: de onde vêm os dados deste diagnóstico.</p>
        <div id="dataSourcesList"></div>
    </div>
</div>

<?php
// Include Advanced Diagnostics Panel
require __DIR__ . '/account-health-advanced.php';
?>

<script nonce="<?= CSP_NONCE ?>">
    // ===================================================================
    // ACCOUNT HEALTH DIAGNOSTIC
    // ===================================================================

    // Toast fallback (caso o objeto global Toast não exista)
    if (typeof Toast === 'undefined') {
        window.Toast = {
            success: (msg) => showToast(msg, 'success'),
            error: (msg) => showToast(msg, 'danger'),
            warning: (msg) => showToast(msg, 'warning'),
        };

        function showToast(message, type) {
            const container = document.getElementById('toastContainer') || createToastContainer();
            const toast = document.createElement('div');
            toast.className = `alert alert-${type} alert-dismissible fade show shadow-sm`;
            toast.style.cssText = 'min-width:280px;margin-bottom:0.5rem;';
            toast.innerHTML = `${message}<button type="button" class="btn-close btn-sm" data-bs-dismiss="alert"></button>`;
            container.appendChild(toast);
            setTimeout(() => toast.remove(), 4000);
        }

        function createToastContainer() {
            const c = document.createElement('div');
            c.id = 'toastContainer';
            c.style.cssText = 'position:fixed;top:1rem;right:1rem;z-index:9999;';
            document.body.appendChild(c);
            return c;
        }
    }

    let diagnosticData = null;
    let currentFilter = 'all';
    let ratingsChartInstance = null;
    let salesUnitsChartInstance = null;
    let salesRevenueChartInstance = null;
    let sparklineChartInstance = null;
    let historyChartInstance = null;
    let diagnosticLoadedAt = null;
    let refreshTimerInterval = null;
    let autoRefreshInterval = null;
    let trendData = null;
    let completedActions = new Set();
    let dataQualityConnectionErrors = {};
    let retryCount = 0;
    const MAX_RETRIES = 3;
    const CACHE_KEY = 'accountHealthCache';
    const CACHE_TTL = 15 * 60 * 1000; // 15 min
    const AUTO_REFRESH_MS = 15 * 60 * 1000; // 15 min
    const COMPLETED_KEY = 'accountHealthCompleted';
    const GOAL_KEY = 'accountHealthGoal';

    // Carregar diagnóstico ao abrir a página
    document.addEventListener('DOMContentLoaded', function() {
        // Carregar ações concluídas do localStorage
        loadCompletedActions();

        // Configurar IntersectionObserver para lazy reveal de seções
        setupLazySections();

        // Configurar atalhos de teclado
        setupKeyboardShortcuts();

        // Detectar offline/online
        setupNetworkDetection();

        // Fechar export dropdown ao clicar fora
        document.addEventListener('click', function(e) {
            if (!e.target.closest('#exportDropdown')) closeExportMenu();
        });

        // Tentar cache local para exibição instantânea
        const cached = loadFromCache();
        if (cached) {
            diagnosticData = cached;
            renderDiagnostic(diagnosticData);
            hideLoadingBar();
            diagnosticLoadedAt = new Date();
            startRefreshTimer();
            loadTrendHistory();
        }
        // Carregar dados frescos (atualiza o cache)
        loadDiagnostic(!cached);
        startAutoRefresh();
    });

    async function loadDiagnostic(showLoading = true) {
        if (showLoading) setLoadingProgress(15);
        updateLoadingStep('stepRep');
        try {
            if (showLoading) setLoadingProgress(30);
            updateLoadingStep('stepSeo');
            // ApiClient adiciona retry em 429/503 e tratamento de 401
            const apiFetch = window.ApiClient ? window.ApiClient.fetch : (u, o) => fetch(u, o);
            const response = await apiFetch('/api/account-health/diagnostic');
            if (showLoading) setLoadingProgress(60);
            updateLoadingStep('stepComp');

            if (!response.ok) {
                // Tentar parsear resposta de erro como JSON antes de desistir
                let errorData = null;
                try {
                    errorData = await response.json();
                } catch (e) {
                    /* resposta não é JSON */
                }

                if (errorData?.error === 'account_disconnected') {
                    if (showLoading) hideLoadingBar();
                    hideLoadingSteps();
                    showAccountDisconnected(errorData.nickname, errorData.account_id);
                    return;
                }

                throw new Error(errorData?.error || `HTTP ${response.status}: ${response.statusText}`);
            }

            // Capturar timing do header
            const diagTime = response.headers.get('X-Diagnostic-Time');

            const result = await response.json();
            if (showLoading) setLoadingProgress(80);
            updateLoadingStep('stepOps');

            if (result.success && result.data) {
                retryCount = 0; // Reset retry counter on success
                diagnosticData = result.data;
                saveToCache(diagnosticData);
                updateLoadingStep('stepSales');
                renderDiagnostic(diagnosticData);

                // Mostrar tempo de geração
                displayTimings(diagTime, diagnosticData.timings);

                if (showLoading) setLoadingProgress(90);
                loadTrendHistory();
                diagnosticLoadedAt = new Date();
                startRefreshTimer();
                if (showLoading) {
                    setLoadingProgress(100);
                    setTimeout(() => hideLoadingBar(), 500);
                }
                hideLoadingSteps();
            } else if (result.error === 'account_disconnected') {
                if (showLoading) hideLoadingBar();
                hideLoadingSteps();
                showAccountDisconnected(result.nickname, result.account_id);
            } else {
                if (showLoading) hideLoadingBar();
                hideLoadingSteps();
                showError(result.error || 'Erro ao carregar diagnóstico');
            }
        } catch (error) {
            console.error('Erro ao carregar diagnóstico:', error);

            // Verificar se é erro de rede real (fetch failed) vs erro de parsing
            const isNetworkError = error instanceof TypeError && error.message.includes('fetch');

            // Só faz retry para erros de rede, não para erros de parsing/lógica
            if (isNetworkError && retryCount < MAX_RETRIES) {
                retryCount++;
                const delay = Math.min(1000 * Math.pow(2, retryCount - 1), 8000);
                showRetryToast(retryCount, MAX_RETRIES, delay);
                setTimeout(() => loadDiagnostic(showLoading), delay);
                return;
            }

            if (showLoading) hideLoadingBar();
            hideLoadingSteps();

            if (isNetworkError) {
                showError('Erro de conexão após ' + MAX_RETRIES + ' tentativas. Verifique sua internet e tente novamente.');
            } else {
                showError('Erro ao processar resposta do servidor: ' + (error.message || 'erro desconhecido'));
            }
            retryCount = 0;
        }
    }

    function setLoadingProgress(pct) {
        const bar = document.getElementById('heroLoadingBarFill');
        if (bar) bar.style.width = pct + '%';
    }

    function hideLoadingBar() {
        const barContainer = document.getElementById('heroLoadingBar');
        if (barContainer) {
            barContainer.style.opacity = '0';
            setTimeout(() => {
                barContainer.style.display = 'none';
            }, 300);
        }
    }

    function updateLoadingStep(activeId) {
        const steps = ['stepRep', 'stepSeo', 'stepComp', 'stepOps', 'stepSales'];
        const activeIdx = steps.indexOf(activeId);
        steps.forEach((id, i) => {
            const el = document.getElementById(id);
            if (!el) return;
            if (i < activeIdx) el.className = 'loading-step done';
            else if (i === activeIdx) el.className = 'loading-step active';
            else el.className = 'loading-step pending';
        });
    }

    function hideLoadingSteps() {
        const el = document.getElementById('loadingSteps');
        if (el) el.style.display = 'none';
    }

    function saveToCache(data) {
        try {
            sessionStorage.setItem(CACHE_KEY, JSON.stringify({
                data: data,
                ts: Date.now()
            }));
        } catch (e) {
            /* quota exceeded */
        }
    }

    function loadFromCache() {
        try {
            const raw = sessionStorage.getItem(CACHE_KEY);
            if (!raw) return null;
            const parsed = JSON.parse(raw);
            if (Date.now() - parsed.ts > CACHE_TTL) {
                sessionStorage.removeItem(CACHE_KEY);
                return null;
            }
            return parsed.data;
        } catch (e) {
            return null;
        }
    }

    function startAutoRefresh() {
        if (autoRefreshInterval) clearInterval(autoRefreshInterval);
        autoRefreshInterval = setInterval(() => {
            loadDiagnostic(false);
        }, AUTO_REFRESH_MS);
    }

    // ===================================================================
    // KEYBOARD SHORTCUTS
    // ===================================================================

    function setupKeyboardShortcuts() {
        document.addEventListener('keydown', function(e) {
            // Ignorar se estiver em input/textarea/modal
            if (e.target.tagName === 'INPUT' || e.target.tagName === 'TEXTAREA' || e.target.tagName === 'SELECT') return;
            if (document.querySelector('.modal.show')) return;

            switch (e.key.toLowerCase()) {
                case 'r':
                    e.preventDefault();
                    refreshDiagnostic();
                    break;
                case 'e':
                    e.preventDefault();
                    exportDiagnostic();
                    break;
                case 's':
                    if (!e.ctrlKey && !e.metaKey) {
                        e.preventDefault();
                        shareDiagnostic();
                    }
                    break;
                case 'p':
                    if (!e.ctrlKey && !e.metaKey) {
                        e.preventDefault();
                        window.print();
                    }
                    break;
                case 'g':
                    e.preventDefault();
                    setScoreGoal();
                    break;
                case '?':
                    e.preventDefault();
                    showKeyboardHelp();
                    break;
            }
        });
    }

    function showKeyboardHelp() {
        const helpHtml = `
    <div class="modal fade" id="kbdHelpModal" tabindex="-1">
        <div class="modal-dialog modal-sm">
            <div class="modal-content">
                <div class="modal-header">
                    <h6 class="modal-title"><i class="bi bi-keyboard me-2"></i>Atalhos de Teclado</h6>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <table class="table table-sm mb-0">
                        <tbody>
                            <tr><td><kbd>R</kbd></td><td>Atualizar diagnóstico</td></tr>
                            <tr><td><kbd>E</kbd></td><td>Exportar relatório</td></tr>
                            <tr><td><kbd>S</kbd></td><td>Compartilhar</td></tr>
                            <tr><td><kbd>P</kbd></td><td>Imprimir</td></tr>
                            <tr><td><kbd>G</kbd></td><td>Definir meta de score</td></tr>
                            <tr><td><kbd>?</kbd></td><td>Esta ajuda</td></tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>`;

        document.getElementById('kbdHelpModal')?.remove();
        document.body.insertAdjacentHTML('beforeend', helpHtml);
        if (typeof bootstrap !== 'undefined' && bootstrap.Modal) {
            new bootstrap.Modal(document.getElementById('kbdHelpModal')).show();
        }
    }

    // ===================================================================
    // SHARE DIAGNOSTIC
    // ===================================================================

    function shareDiagnostic() {
        if (!diagnosticData) {
            Toast.warning('Aguarde o diagnóstico carregar');
            return;
        }

        const d = diagnosticData;
        const pillars = d.pillars || {};
        const pillarOrder = ['reputation', 'seo_quality', 'competitiveness', 'operation', 'sales'];

        let text = `\uD83C\uDFE5 Diagnóstico ML — Score: ${d.overall_score}/100 (${d.overall_label})\n`;
        text += `\uD83D\uDCC5 ${formatDateTime(d.generated_at)}\n\n`;

        pillarOrder.forEach(key => {
            const p = pillars[key];
            if (!p) return;
            const emoji = p.score >= 70 ? '\u2705' : (p.score >= 50 ? '\u26A0\uFE0F' : '\u274C');
            text += `${emoji} ${p.name}: ${p.score}/100\n`;
        });

        const summary = d.summary || {};
        if (summary.recommendation) {
            text += `\n\uD83D\uDCA1 ${summary.recommendation}`;
        }

        if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(text).then(() => {
                const btn = document.getElementById('btnShare');
                btn.classList.add('btn-share-copied');
                btn.innerHTML = '<i class="bi bi-check2 me-1"></i>Copiado!';
                Toast.success('Diagnóstico copiado para a área de transferência!');
                setTimeout(() => {
                    btn.classList.remove('btn-share-copied');
                    btn.innerHTML = '<i class="bi bi-share me-1"></i>Compartilhar<span class="kbd-hint">S</span>';
                }, 2000);
            }).catch(() => {
                Toast.error('Não foi possível copiar');
            });
        } else {
            // Fallback: selecionar em textarea temporária
            const ta = document.createElement('textarea');
            ta.value = text;
            ta.style.cssText = 'position:fixed;opacity:0;';
            document.body.appendChild(ta);
            ta.select();
            document.execCommand('copy');
            ta.remove();
            Toast.success('Diagnóstico copiado!');
        }
    }

    // ===================================================================
    // NETWORK DETECTION
    // ===================================================================

    function setupNetworkDetection() {
        const banner = document.getElementById('offlineBanner');
        if (!banner) return;

        const update = () => {
            if (!navigator.onLine) {
                banner.classList.add('show');
            } else {
                banner.classList.remove('show');
            }
        };

        window.addEventListener('online', () => {
            banner.classList.remove('show');
            // Auto-refresh quando voltar online se dados são velhos
            if (diagnosticLoadedAt) {
                const age = Date.now() - diagnosticLoadedAt.getTime();
                if (age > CACHE_TTL) loadDiagnostic(true);
            }
        });
        window.addEventListener('offline', update);
        update();
    }

    // ===================================================================
    // RETRY TOAST
    // ===================================================================

    function showRetryToast(attempt, maxAttempts, delayMs) {
        // Remove toast anterior
        document.querySelector('.retry-toast')?.remove();

        const seconds = Math.round(delayMs / 1000);
        const toast = document.createElement('div');
        toast.className = 'retry-toast';
        toast.setAttribute('role', 'status');
        toast.setAttribute('aria-live', 'polite');
        toast.innerHTML = `
        <div class="spinner-border text-light" role="status"><span class="visually-hidden">Carregando...</span></div>
        <span>Tentativa ${attempt}/${maxAttempts} em ${seconds}s...</span>`;
        document.body.appendChild(toast);

        setTimeout(() => toast.remove(), delayMs + 500);
    }

    // ===================================================================
    // TIMING DISPLAY
    // ===================================================================

    function displayTimings(headerTime, backendTimings) {
        const badge = document.getElementById('timingBadge');
        const value = document.getElementById('timingValue');
        if (!badge || !value) return;

        let display = '';
        let tooltip = '';

        if (headerTime) {
            display = headerTime;
            tooltip = 'Tempo total do servidor: ' + headerTime;
        }

        if (backendTimings && typeof backendTimings === 'object') {
            const parts = Object.entries(backendTimings)
                .map(([k, v]) => `${k}: ${v}ms`)
                .join('\n');
            tooltip += (tooltip ? '\n\n' : '') + 'Detalhamento:\n' + parts;

            // Se não tem header time, mostrar soma dos timings
            if (!display) {
                const total = Object.values(backendTimings).reduce((a, b) => a + b, 0);
                display = Math.round(total) + 'ms';
            }
        }

        if (display) {
            value.textContent = display;
            badge.title = tooltip;
            badge.style.display = '';
        }
    }

    // ===================================================================
    // EXPORT DROPDOWN
    // ===================================================================

    function toggleExportMenu() {
        const menu = document.getElementById('exportMenu');
        const btn = document.getElementById('btnExport');
        const isOpen = menu.classList.contains('show');
        menu.classList.toggle('show');
        btn.setAttribute('aria-expanded', String(!isOpen));
    }

    function closeExportMenu() {
        const menu = document.getElementById('exportMenu');
        const btn = document.getElementById('btnExport');
        if (menu) menu.classList.remove('show');
        if (btn) btn.setAttribute('aria-expanded', 'false');
    }

    function exportActionsCsv() {
        if (!diagnosticData) {
            Toast.warning('Aguarde o diagnóstico carregar');
            return;
        }

        const actions = diagnosticData.action_items || [];
        if (actions.length === 0) {
            Toast.warning('Nenhuma ação para exportar');
            return;
        }

        // Sanitize cell value to prevent CSV injection (formula injection)
        const csvSafe = (val) => {
            const s = String(val ?? '').replace(/"/g, '""');
            // Prefix formula-triggering characters with a single quote
            if (/^[=+\-@\t\r]/.test(s)) return '"' + "'" + s + '"';
            return '"' + s + '"';
        };

        const headers = ['Prioridade', 'Severidade', 'Pilar', 'Problema', 'Impacto', 'Ação Recomendada', 'Score Impacto'];
        const rows = actions.map((a, i) => [
            i + 1,
            a.severity.toUpperCase(),
            csvSafe(a.pillar_name || a.pillar),
            csvSafe(a.message || ''),
            csvSafe(a.impact || ''),
            csvSafe(a.action || ''),
            a.impact_score || 0,
        ]);

        let csv = '\uFEFF'; // BOM for Excel UTF-8
        csv += headers.join(';') + '\n';
        rows.forEach(row => {
            csv += row.join(';') + '\n';
        });

        const blob = new Blob([csv], {
            type: 'text/csv;charset=utf-8'
        });
        const url = URL.createObjectURL(blob);
        const a = document.createElement('a');
        a.href = url;
        a.download = 'acoes_saude_conta_' + new Date().toISOString().slice(0, 10) + '.csv';
        a.click();
        URL.revokeObjectURL(url);
        Toast.success('CSV exportado com ' + actions.length + ' ações');
    }

    function exportJson() {
        if (!diagnosticData) {
            Toast.warning('Aguarde o diagnóstico carregar');
            return;
        }

        const blob = new Blob([JSON.stringify(diagnosticData, null, 2)], {
            type: 'application/json;charset=utf-8'
        });
        const url = URL.createObjectURL(blob);
        const a = document.createElement('a');
        a.href = url;
        a.download = 'diagnostico_saude_' + new Date().toISOString().slice(0, 10) + '.json';
        a.click();
        URL.revokeObjectURL(url);
        Toast.success('JSON exportado');
    }

    // ===================================================================
    // SCORE GOAL TRACKER
    // ===================================================================

    function loadScoreGoal() {
        try {
            return parseInt(localStorage.getItem(GOAL_KEY)) || null;
        } catch (e) {
            return null;
        }
    }

    function saveScoreGoal(goal) {
        try {
            if (goal) localStorage.setItem(GOAL_KEY, goal);
            else localStorage.removeItem(GOAL_KEY);
        } catch (e) {
            /* localStorage indisponível */
        }
    }

    function setScoreGoal() {
        const currentGoal = loadScoreGoal();
        const currentScore = diagnosticData?.overall_score || 0;
        const input = prompt(
            `Defina sua meta de score (0-100).\nScore atual: ${currentScore}\nMeta atual: ${currentGoal || 'nenhuma'}\n\nDeixe vazio para remover a meta.`,
            currentGoal || Math.min(100, currentScore + 10)
        );

        if (input === null) return; // Cancelou

        if (input === '') {
            saveScoreGoal(null);
            document.getElementById('scoreGoalContainer').style.display = 'none';
            Toast.success('Meta removida');
            return;
        }

        const goal = parseInt(input);
        if (isNaN(goal) || goal < 1 || goal > 100) {
            Toast.error('Meta inválida. Use um número entre 1 e 100.');
            return;
        }

        saveScoreGoal(goal);
        renderScoreGoal(currentScore, goal);
        Toast.success(`Meta definida: ${goal} pontos!`);
    }

    function renderScoreGoal(currentScore, goal) {
        if (!goal) goal = loadScoreGoal();
        if (!goal) return;

        const container = document.getElementById('scoreGoalContainer');
        const fill = document.getElementById('scoreGoalFill');
        const marker = document.getElementById('scoreGoalMarker');
        const text = document.getElementById('scoreGoalText');

        container.style.display = 'flex';

        const progress = Math.min(100, (currentScore / goal) * 100);
        const level = currentScore >= goal ? '#22c55e' : (progress >= 70 ? '#f59e0b' : '#ef4444');

        fill.style.width = Math.min(progress, 100) + '%';
        fill.style.background = level;
        marker.style.left = Math.min(goal, 100) + '%';
        marker.setAttribute('data-goal', goal);

        if (currentScore >= goal) {
            text.innerHTML = `<span class="text-success"><i class="bi bi-trophy-fill me-1"></i>Meta atingida!</span>`;
        } else {
            text.textContent = `${currentScore}/${goal} (faltam ${goal - currentScore})`;
        }
    }

    // ===================================================================
    // ACTION COMPLETION TRACKING
    // ===================================================================

    function loadCompletedActions() {
        try {
            const data = JSON.parse(localStorage.getItem(COMPLETED_KEY) || '{}');
            // Limpar entradas com mais de 30 dias
            const now = Date.now();
            const thirtyDays = 30 * 24 * 60 * 60 * 1000;
            Object.entries(data).forEach(([key, ts]) => {
                if (now - ts > thirtyDays) delete data[key];
                else completedActions.add(key);
            });
            localStorage.setItem(COMPLETED_KEY, JSON.stringify(data));
        } catch (e) {
            /* localStorage indisponível */
        }
    }

    function toggleActionComplete(actionKey, el) {
        const item = el.closest('.action-item');
        if (completedActions.has(actionKey)) {
            completedActions.delete(actionKey);
            item.classList.remove('completed');
        } else {
            completedActions.add(actionKey);
            item.classList.add('completed');
        }

        // Salvar no localStorage
        try {
            const data = {};
            completedActions.forEach(k => {
                data[k] = Date.now();
            });
            localStorage.setItem(COMPLETED_KEY, JSON.stringify(data));
        } catch (e) {
            /* localStorage indisponível */
        }

        updateCompletedCounter();
    }

    function updateCompletedCounter() {
        const counter = document.getElementById('completedCounter');
        const total = document.querySelectorAll('.action-item').length;
        const done = document.querySelectorAll('.action-item.completed').length;

        if (done > 0 && counter) {
            counter.style.display = 'inline';
            counter.textContent = `${done}/${total} concluídos`;
        } else if (counter) {
            counter.style.display = 'none';
        }
    }

    // ===================================================================
    // LAZY SECTION REVEAL
    // ===================================================================

    function setupLazySections() {
        if (!('IntersectionObserver' in window)) {
            // Fallback: mostrar tudo
            document.querySelectorAll('.section-lazy').forEach(el => el.classList.add('section-visible'));
            return;
        }

        const observer = new IntersectionObserver((entries) => {
            entries.forEach(entry => {
                if (entry.isIntersecting) {
                    entry.target.classList.add('section-visible');
                    observer.unobserve(entry.target);
                }
            });
        }, {
            threshold: 0.05,
            rootMargin: '100px'
        });

        document.querySelectorAll('.section-lazy').forEach(el => observer.observe(el));
    }

    function startRefreshTimer() {
        if (refreshTimerInterval) clearInterval(refreshTimerInterval);
        refreshTimerInterval = setInterval(() => {
            if (!diagnosticLoadedAt) return;
            const seconds = Math.floor((new Date() - diagnosticLoadedAt) / 1000);
            const timerText = document.getElementById('refreshTimerText');
            if (!timerText) return;
            if (seconds < 60) {
                timerText.textContent = 'agora';
            } else if (seconds < 3600) {
                timerText.textContent = Math.floor(seconds / 60) + 'min atrás';
            } else {
                timerText.textContent = Math.floor(seconds / 3600) + 'h atrás';
            }
        }, 30000);
    }

    async function loadTrendHistory() {
        try {
            const result = await requestJson('/api/account-health/history?days=30');
            if (result.success && result.data) {
                trendData = result.data;
                renderTrendSparkline(result.data);
                renderPillarTrends(result.data.trend);
            }
        } catch (e) {
            console.log('Histórico de tendência indisponível');
        }
    }

    async function refreshDiagnostic() {
        const btn = document.getElementById('btnRefresh');
        btn.disabled = true;
        btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Atualizando...';

        try {
            const result = await requestJson('/api/account-health/refresh', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || ''
                }
            });

            if (result.success && result.data) {
                diagnosticData = result.data;
                saveToCache(diagnosticData);
                renderDiagnostic(diagnosticData);
                diagnosticLoadedAt = new Date();
                document.getElementById('refreshTimerText').textContent = 'agora';
                loadTrendHistory();
                Toast.success('Diagnóstico atualizado!');
            } else {
                Toast.error(result.error || 'Erro ao atualizar');
            }
        } catch (error) {
            Toast.error('Erro de conexão');
        } finally {
            btn.disabled = false;
            btn.innerHTML = '<i class="bi bi-arrow-clockwise me-1"></i>Atualizar<span class="kbd-hint">R</span>';
        }
    }

    // ===================================================================
    // RENDER FUNCTIONS
    // ===================================================================

    function renderDiagnostic(data) {
        renderDataQualityBadge(data.data_quality);
        renderHeroScore(data.overall_score, data.overall_level, data.overall_label, data.summary);
        renderPillarCards(data.pillars);
        renderScoreDeltas(data.previous_scores, data.overall_score, data.pillars);
        renderWeeklyPlan(data.weekly_plan);
        renderQuickActions(data);
        renderActionItems(data.action_items);
        renderReputationPanel(data.pillars?.reputation?.details || {});
        renderWorstItems(data.pillars?.seo_quality?.details?.worst_items || []);
        renderPriceAnalysis(data.pillars?.competitiveness?.details || {});
        renderAttentionItems(data.items_attention);
        renderStaleListings(data.stale_listings);
        renderCategoryBreakdown(data.category_breakdown);
        renderPausedRecovery(data.paused_recovery);
        renderSalesSection(data.pillars?.sales?.details || {});
        renderOperationPanel(data.pillars?.operation?.details || {}, data.pillars?.operation?.score || 0);
        renderCrossInsights(data.cross_insights || []);
        renderDataSourcesPanel(data.data_sources);

        // Score goal tracker
        const savedGoal = loadScoreGoal();
        if (savedGoal) {
            renderScoreGoal(data.overall_score, savedGoal);
        }

        if (data.generated_at) {
            document.getElementById('generatedAt').textContent = 'Atualizado em: ' + formatDateTime(data.generated_at);
        }

        // Re-trigger IntersectionObserver para novas seções reveladas
        document.querySelectorAll('.section-lazy:not(.section-visible)').forEach(el => {
            const rect = el.getBoundingClientRect();
            if (rect.top < window.innerHeight + 100) {
                el.classList.add('section-visible');
            }
        });
    }

    function renderDataQualityBadge(dataQuality) {
        const badge = document.getElementById('dataQualityBadge');
        if (!badge) return;

        // Se não houver data_quality no response, ocultar badge
        if (!dataQuality || dataQuality.percentage_real === 100) {
            dataQualityConnectionErrors = {};
            badge.removeAttribute('data-action');
            badge.removeAttribute('data-keyaction');
            badge.removeAttribute('tabindex');
            badge.removeAttribute('role');
            badge.style.cursor = '';
            badge.style.display = 'none';
            return;
        }

        let icon, text, className, title;
        const pct = dataQuality.percentage_real || 0;

        if (pct === 0) {
            icon = '🔴';
            text = 'Dados Indisponíveis';
            className = 'mock';
            title = 'Todas as contas estão desconectadas ou com tokens expirados. Reconecte para ver dados reais.';
        } else if (pct < 60) {
            icon = '⚠️';
            text = `${Math.round(pct)}% Dados Reais`;
            className = 'mock';
            const errorCount = Object.keys(dataQuality.errors || {}).length;
            title = `${errorCount} ${errorCount === 1 ? 'pilar falhou' : 'pilares falharam'} ao obter dados reais. Verifique as conexões das contas.`;
        } else if (pct < 100) {
            icon = '⚡';
            text = `${Math.round(pct)}% Dados Reais`;
            className = 'partial';
            const errorCount = Object.keys(dataQuality.errors || {}).length;
            title = `Alguns pilares estão usando dados aproximados (${errorCount} ${errorCount === 1 ? 'erro' : 'erros'}).`;
        } else {
            // 100% - não mostrar badge
            badge.style.display = 'none';
            return;
        }

        badge.innerHTML = `${icon} <span>${text}</span>`;
        badge.className = `data-quality-badge ${className}`;
        badge.title = title;
        badge.style.display = 'inline-flex';

        dataQualityConnectionErrors = dataQuality.errors || {};

        // Se needs_connection, habilitar ação delegada de reconexão
        if (dataQuality.needs_connection) {
            badge.style.cursor = 'pointer';
            badge.setAttribute('data-action', 'show-reconnection-prompt');
            badge.setAttribute('data-keyaction', 'show-reconnection-prompt');
            badge.setAttribute('tabindex', '0');
            badge.setAttribute('role', 'button');
        } else {
            badge.style.cursor = '';
            badge.removeAttribute('data-action');
            badge.removeAttribute('data-keyaction');
            badge.removeAttribute('tabindex');
            badge.removeAttribute('role');
        }
    }

    function showReconnectionPrompt(errors) {
        const errorList = Object.entries(errors || {})
            .map(([pillar, error]) => `<li><strong>${escapeHtml(pillar)}:</strong> ${escapeHtml(error)}</li>`)
            .join('');

        const html = `
        <div class="mb-3">
            <p class="mb-2">Os seguintes pilares não conseguiram obter dados reais do Mercado Livre:</p>
            <ul class="text-start">${errorList}</ul>
        </div>
        <p class="mb-0">Deseja reconectar suas contas agora?</p>
    `;

        if (typeof Swal !== 'undefined') {
            Swal.fire({
                icon: 'warning',
                title: 'Contas Desconectadas',
                html: html,
                showCancelButton: true,
                confirmButtonText: '<i class="bi bi-link-45deg"></i> Reconectar Agora',
                cancelButtonText: 'Depois',
                confirmButtonColor: '#3b82f6',
                cancelButtonColor: '#6b7280'
            }).then((result) => {
                if (result.isConfirmed) {
                    window.location.href = '/oauth/mercadolivre';
                }
            });
        } else {
            if (confirm('Algumas contas estão desconectadas. Reconectar agora?')) {
                window.location.href = '/oauth/mercadolivre';
            }
        }
    }

    function renderHeroScore(score, level, label, summary) {
        const circle = document.getElementById('scoreCircle');
        const fill = document.getElementById('scoreFill');
        const valueEl = document.getElementById('scoreValue');
        const labelEl = document.getElementById('scoreLabel');

        // Set score class
        circle.className = 'score-circle score-' + level;

        // Animate circle
        const circumference = 2 * Math.PI * 80; // r=80
        const offset = circumference - (score / 100) * circumference;
        setTimeout(() => {
            fill.style.strokeDashoffset = offset;
        }, 100);

        // Animate number
        animateNumber(valueEl, 0, score, 1500);
        labelEl.textContent = label;

        // Celebration animation for great scores
        if (score >= 85) {
            setTimeout(() => circle.classList.add('score-celebrate'), 1600);
            setTimeout(() => circle.classList.remove('score-celebrate'), 2200);
        }

        // Summary
        if (summary) {
            document.getElementById('mainRecommendation').textContent = summary.recommendation || '';
            document.getElementById('statCritical').textContent = summary.critical_count || 0;
            document.getElementById('statWarning').textContent = summary.warning_count || 0;
            document.getElementById('statActions').textContent = summary.total_actions || 0;

            // Worst pillar + potential gain
            const worstEl = document.getElementById('statWorstPillar');
            const gainEl = document.getElementById('statPotentialGain');
            if (worstEl && summary.worst_pillar) {
                worstEl.textContent = summary.worst_pillar;
                worstEl.closest('.hero-stat-item')?.classList.remove('d-none');
            }
            if (gainEl && summary.potential_gain > 0) {
                gainEl.textContent = '+' + summary.potential_gain;
                gainEl.closest('.hero-stat-item')?.classList.remove('d-none');
            }
        }
    }

    function renderPillarCards(pillars) {
        const container = document.getElementById('pillarCards');
        if (!pillars) return;

        const pillarOrder = ['reputation', 'seo_quality', 'competitiveness', 'operation', 'sales'];
        let html = '';

        pillarOrder.forEach(key => {
            const p = pillars[key];
            if (!p) return;

            const colorClass = getColorClass(p.level);
            const bgColor = getColorBg(p.level);
            const issueCount = (p.issues || []).length;

            html += `
        <div class="col-12 col-sm-6 col-lg">
            <div class="card pillar-card h-100" data-action="show-pillar-detail" data-pillar="${key}" data-keyaction="show-pillar-detail" tabindex="0" role="button" aria-label="${escapeHtml(p.name)}: score ${p.score} de 100">
                <div class="card-body">
                    <div class="d-flex align-items-start gap-3 mb-2">
                        <div class="pillar-icon ${bgColor}">
                            <i class="bi ${p.icon} ${colorClass}"></i>
                        </div>
                        <div style="flex:1">
                            <div class="d-flex align-items-center justify-content-between">
                                <small class="text-muted fw-medium">${escapeHtml(p.name)}</small>
                                ${issueCount > 0 ? `<span class="pillar-issues-badge bg-${p.level === 'critical' ? 'danger' : (p.level === 'warning' ? 'warning' : 'success')} bg-opacity-10 ${colorClass}">${issueCount} ${issueCount === 1 ? 'problema' : 'problemas'}</span>` : ''}
                            </div>
                            <div class="pillar-score-value ${colorClass}">${p.score}</div>
                        </div>
                    </div>
                    <div class="pillar-score-bar">
                        <div class="pillar-score-fill ${getBgClass(p.level)}" style="width: 0%" data-target="${p.score}"></div>
                    </div>
                </div>
            </div>
        </div>`;
        });

        container.innerHTML = html;

        // Animate bars
        setTimeout(() => {
            document.querySelectorAll('.pillar-score-fill[data-target]').forEach(bar => {
                bar.style.width = bar.dataset.target + '%';
            });
        }, 200);
    }

    function renderActionItems(actions) {
        const container = document.getElementById('actionItemsList');
        const countEl = document.getElementById('actionCount');

        if (!actions || actions.length === 0) {
            container.innerHTML = `
            <div class="text-center py-4">
                <i class="bi bi-check-circle text-success" style="font-size: 3rem;"></i>
                <p class="text-muted mt-2">Nenhuma ação necessária! Sua conta está ótima.</p>
            </div>`;
            countEl.textContent = '0';
            return;
        }

        countEl.textContent = actions.length;

        let html = '';
        actions.forEach((action, idx) => {
            const actionKey = (action.type || '') + '_' + idx;
            const isCompleted = completedActions.has(actionKey);
            const completedClass = isCompleted ? ' completed' : '';
            const display = currentFilter === 'all' || currentFilter === action.severity ? '' : 'display:none;';
            const quickLink = getQuickActionLink(action.type);
            const impactBadge = action.impact_score !== undefined ?
                `<span class="badge ${action.impact_score >= 60 ? 'bg-danger' : (action.impact_score >= 30 ? 'bg-warning text-dark' : 'bg-info')} bg-opacity-75" title="Impacto estimado no score geral"><i class="bi bi-lightning-charge-fill me-1"></i>${action.impact_score}</span>` :
                '';
            html += `
        <div class="action-item severity-${action.severity}${completedClass}" data-severity="${action.severity}" data-action-key="${actionKey}" style="${display}" role="article" tabindex="0">
            <div class="d-flex align-items-start gap-3">
                <div class="flex-shrink-0 mt-1">
                    <span class="severity-badge ${action.severity}">${getSeverityLabel(action.severity)}</span>
                </div>
                <div style="flex:1">
                    <div class="fw-semibold mb-1">${escapeHtml(action.message)}</div>
                    <div class="text-muted small mb-1">
                        <i class="bi bi-lightning-charge me-1"></i>${escapeHtml(action.impact)}
                    </div>
                    <div class="d-flex align-items-center gap-2 flex-wrap">
                        <span class="text-primary small">
                            <i class="bi bi-arrow-right-circle me-1"></i><strong>Ação:</strong> ${escapeHtml(action.action)}
                        </span>
                        ${quickLink ? `<a href="${quickLink.url}" class="quick-action-btn btn btn-outline-primary btn-sm"><i class="bi ${quickLink.icon} me-1"></i>${quickLink.label}</a>` : ''}
                    </div>
                </div>
                <div class="flex-shrink-0 d-flex flex-column align-items-end gap-1">
                    <span class="badge bg-light text-dark">${escapeHtml(action.pillar_name || '')}</span>
                    ${impactBadge}
                    <button class="btn btn-sm btn-outline-success btn-complete" data-action="toggle-action-complete" data-action-key="${actionKey}" title="${isCompleted ? 'Desmarcar' : 'Marcar como concluído'}" aria-label="${isCompleted ? 'Desmarcar concluído' : 'Marcar como concluído'}">
                        <i class="bi ${isCompleted ? 'bi-check-circle-fill' : 'bi-check-circle'}"></i>
                    </button>
                </div>
            </div>
        </div>`;
        });

        container.innerHTML = html;
        updateCompletedCounter();
    }

    function normalizeExternalUrl(url) {
        if (!url || typeof url !== 'string') return '';
        const trimmed = url.trim();
        if (!trimmed) return '';
        if (/^(data:|blob:)/i.test(trimmed)) return trimmed;
        if (trimmed.startsWith('//')) return window.location.protocol + trimmed;
        if (/^http:\/\//i.test(trimmed)) return trimmed.replace(/^http:\/\//i, 'https://');
        return trimmed;
    }

    function bindImageFallbacks(container) {
        if (!container) return;

        container.querySelectorAll('img[data-fallback-src]').forEach(img => {
            const fallbackSrc = img.getAttribute('data-fallback-src');
            if (!fallbackSrc) return;

            img.addEventListener('error', () => {
                if (img.dataset.fallbackApplied === '1') return;
                img.dataset.fallbackApplied = '1';
                img.src = fallbackSrc;
            }, {
                once: true
            });
        });
    }

    function renderWorstItems(items) {
        const container = document.getElementById('worstItemsList');

        if (!items || items.length === 0) {
            container.innerHTML = `
            <div class="text-center py-3">
                <i class="bi bi-check-circle text-success" style="font-size: 2rem;"></i>
                <p class="text-muted small mt-2 mb-0">Todos os anúncios estão OK!</p>
            </div>`;
            return;
        }

        let html = '';
        items.forEach(item => {
            const scoreColor = item.score < 40 ? 'bg-danger' : (item.score < 70 ? 'bg-warning' : 'bg-success');
            const problems = (item.problems || []).slice(0, 3).join(' · ');
            const fallbackImg = 'data:image/svg+xml,%3Csvg xmlns=%27http://www.w3.org/2000/svg%27 width=%2764%27 height=%2764%27 fill=%27%23ccc%27%3E%3Crect width=%2764%27 height=%2764%27 fill=%27%23f0f0f0%27/%3E%3Ctext x=%2732%27 y=%2736%27 text-anchor=%27middle%27 font-size=%2712%27%3ESem img%3C/text%3E%3C/svg%3E';

            // Badges ML
            let badgeHtml = '';
            if (item.in_catalog) {
                badgeHtml += '<span class="badge bg-primary bg-opacity-10 text-primary me-1" title="No catálogo ML"><i class="bi bi-book-half"></i> Catálogo</span>';
            } else {
                badgeHtml += '<span class="badge bg-secondary bg-opacity-10 text-secondary me-1" title="Fora do catálogo"><i class="bi bi-book"></i> Sem catálogo</span>';
            }
            if (item.ml_bonus && item.ml_bonus > 0) {
                badgeHtml += `<span class="badge bg-success bg-opacity-10 text-success me-1" title="Bônus ML: +${item.ml_bonus}pts"><i class="bi bi-award"></i> +${item.ml_bonus}</span>`;
            }

            // Score breakdown mini bars
            let breakdownHtml = '';
            if (item.title_score !== undefined) {
                const bars = [{
                        label: 'Tít',
                        val: item.title_score || 0,
                        max: 20,
                        color: '#0d6efd'
                    },
                    {
                        label: 'Img',
                        val: item.images_score || 0,
                        max: 20,
                        color: '#198754'
                    },
                    {
                        label: 'Atr',
                        val: item.attributes_score || 0,
                        max: 20,
                        color: '#ffc107'
                    },
                ];
                breakdownHtml = '<div class="d-flex gap-1 mt-1">';
                bars.forEach(b => {
                    const pct = Math.round(b.val / b.max * 100);
                    breakdownHtml += `<div class="d-flex align-items-center gap-1" title="${b.label}: ${b.val}/${b.max}">
                    <span style="font-size:10px;color:#999;width:18px;">${b.label}</span>
                    <div style="width:30px;height:4px;background:#e9ecef;border-radius:2px;">
                        <div style="width:${pct}%;height:100%;background:${b.color};border-radius:2px;"></div>
                    </div>
                </div>`;
                });
                breakdownHtml += '</div>';
            }

            // Missing required attributes
            let missingHtml = '';
            const missing = item.missing_required || [];
            if (missing.length > 0) {
                missingHtml = `<div class="mt-1"><span class="badge bg-danger bg-opacity-10 text-danger" style="font-size:10px;" title="${escapeHtml(missing.join(', '))}"><i class="bi bi-exclamation-triangle"></i> ${missing.length} atrib. obrig. faltando</span></div>`;
            }

            html += `
        <div class="item-row d-flex align-items-start gap-3">
            <img src="${escapeHtml(normalizeExternalUrl(item.thumbnail) || fallbackImg)}" class="item-thumb" alt="" loading="lazy"
                 data-fallback-src="${fallbackImg}">
            <div style="flex:1;min-width:0;">
                <div class="fw-medium text-truncate small">${escapeHtml(item.title)}</div>
                <div class="text-muted small text-truncate">${escapeHtml(problems || 'Verificar anúncio')}</div>
                <div class="d-flex align-items-center gap-2 mt-1">
                    <span class="text-muted small">R$ ${formatPrice(item.price)}</span>
                    ${badgeHtml}
                </div>
                ${breakdownHtml}
                ${missingHtml}
            </div>
            <div class="flex-shrink-0 d-flex flex-column align-items-center gap-1">
                <div class="item-score-mini ${scoreColor} text-white">${item.score}</div>
                <a href="${escapeHtml(normalizeExternalUrl(item.permalink) || '#')}" target="_blank" rel="noopener" class="btn btn-sm btn-outline-primary" title="Ver no ML">
                    <i class="bi bi-box-arrow-up-right"></i>
                </a>
            </div>
        </div>`;
        });

        container.innerHTML = html;
        bindImageFallbacks(container);
    }

    function renderPriceAnalysis(details) {
        const container = document.getElementById('priceAnalysisList');

        const analyzed = details.analyzed || 0;
        const goodItems = details.good_items || 0;
        const noFreeShipping = details.no_free_shipping || 0;
        const noGoldPro = details.no_gold_pro || 0;
        const lowHealth = details.low_health || 0;
        const zeroSales = details.zero_sales || 0;

        if (analyzed === 0) {
            container.innerHTML = `
            <div class="text-center py-3">
                <i class="bi bi-hourglass text-muted" style="font-size: 2rem;"></i>
                <p class="text-muted small mt-2 mb-0">Analisando competitividade...</p>
            </div>`;
            return;
        }

        let html = '';

        // Summary badges
        const goodPct = Math.round(goodItems / analyzed * 100);
        html += `<div class="d-flex flex-wrap gap-2 mb-3">
        <div class="badge bg-success bg-opacity-10 text-success p-2">
            <i class="bi bi-check-circle me-1"></i>${goodItems} competitivos (${goodPct}%)
        </div>
        <div class="badge bg-info bg-opacity-10 text-info p-2">
            <i class="bi bi-box-seam me-1"></i>${analyzed} analisados
        </div>
    </div>`;

        // Competitive factors breakdown
        const factors = [{
                label: 'Tipo Anúncio',
                score: details.avg_listing_type_score || 0,
                max: 15,
                icon: 'bi-star',
                color: 'primary'
            },
            {
                label: 'Frete Grátis',
                score: details.avg_shipping_score || 0,
                max: 15,
                icon: 'bi-truck',
                color: 'success'
            },
            {
                label: 'Logística',
                score: details.avg_logistics_score || 0,
                max: 15,
                icon: 'bi-box-seam',
                color: 'info'
            },
            {
                label: 'Saúde ML',
                score: details.avg_health_score || 0,
                max: 15,
                icon: 'bi-heart-pulse',
                color: 'danger'
            },
            {
                label: 'Catálogo',
                score: details.avg_catalog_score || 0,
                max: 10,
                icon: 'bi-book-half',
                color: 'primary'
            },
            {
                label: 'Qualidade',
                score: details.avg_quality_score || 0,
                max: 15,
                icon: 'bi-image',
                color: 'warning'
            },
            {
                label: 'Vendas',
                score: details.avg_sales_score || 0,
                max: 10,
                icon: 'bi-cart',
                color: 'success'
            },
        ];

        factors.forEach(f => {
            const pct = Math.round(f.score / f.max * 100);
            const barColor = pct >= 70 ? 'bg-success' : (pct >= 40 ? 'bg-warning' : 'bg-danger');
            html += `
        <div class="d-flex align-items-center gap-2 mb-2">
            <i class="bi ${f.icon} text-${f.color}" style="width:20px;"></i>
            <span class="small" style="width:90px;">${f.label}</span>
            <div class="progress flex-grow-1" style="height:8px;">
                <div class="progress-bar ${barColor}" style="width:${pct}%"></div>
            </div>
            <span class="small fw-bold" style="width:35px;text-align:right;">${pct}%</span>
        </div>`;
        });

        // Issues summary
        const issuesList = [];
        if (noFreeShipping > 0) issuesList.push(`<span class="text-danger"><i class="bi bi-truck"></i> ${noFreeShipping} sem frete grátis</span>`);
        if (noGoldPro > 0) issuesList.push(`<span class="text-warning"><i class="bi bi-star"></i> ${noGoldPro} não Premium</span>`);
        if (lowHealth > 0) issuesList.push(`<span class="text-danger"><i class="bi bi-heart-pulse"></i> ${lowHealth} saúde baixa</span>`);
        if (zeroSales > 0) issuesList.push(`<span class="text-muted"><i class="bi bi-graph-down"></i> ${zeroSales} sem vendas</span>`);

        if (issuesList.length > 0) {
            html += `<div class="mt-3 pt-2 border-top"><div class="small text-muted mb-1">Pontos a melhorar:</div>
            <div class="d-flex flex-wrap gap-2 small">${issuesList.join('')}</div></div>`;
        }

        // Trend keywords
        const trends = details.trend_keywords || [];
        if (trends.length > 0) {
            html += `<div class="mt-3 pt-2 border-top">
            <div class="small text-muted mb-1"><i class="bi bi-fire text-danger"></i> Tendências da categoria:</div>
            <div class="d-flex flex-wrap gap-1">
                ${trends.slice(0, 8).map(k => `<span class="badge bg-light text-dark border">${escapeHtml(k)}</span>`).join('')}
            </div></div>`;
        }

        container.innerHTML = html;
    }

    function renderAttentionItems(items) {
        const section = document.getElementById('attentionSection');
        if (!section) return;
        const container = document.getElementById('attentionList');

        if (!items || items.length === 0) {
            section.style.display = 'none';
            return;
        }

        section.style.display = 'block';
        let html = '';

        items.forEach(item => {
            html += `
        <div class="attention-card severity-${item.severity}">
            <i class="bi ${item.icon} fs-4"></i>
            <div style="flex:1">
                <div class="fw-semibold">${escapeHtml(item.message)}</div>
                <div class="text-muted small">${escapeHtml(item.action)}</div>
            </div>
            <span class="badge bg-${item.severity === 'critical' ? 'danger' : 'warning'}">${item.count}</span>
        </div>`;
        });

        container.innerHTML = html;
    }

    // =========================================================================
    // STALE LISTINGS (Anúncios Parados)
    // =========================================================================

    let staleExpanded = false;
    const STALE_INITIAL_SHOW = 10;

    function renderStaleListings(staleData) {
        const section = document.getElementById('staleSection');
        if (!section) return;

        if (!staleData || !staleData.summary || staleData.summary.total_stale === 0) {
            section.style.display = 'none';
            return;
        }

        section.style.display = 'block';
        const summary = staleData.summary;
        const items = staleData.items || [];
        const impact = staleData.impact || {};

        // Summary metrics
        document.getElementById('staleCountBadge').textContent = summary.total_stale;
        document.getElementById('staleTotalCount').textContent = summary.total_stale;
        document.getElementById('stalePercent').textContent = summary.stale_percent + '%';
        document.getElementById('staleCriticalCount').textContent = summary.critical_count;
        document.getElementById('staleFrozenValue').textContent = 'R$ ' + formatPrice(summary.frozen_value || 0);

        // Cause diagnosis
        const causes = summary.cause_counts || {};
        const causesRow = document.getElementById('staleCausesRow');
        if (Object.keys(causes).length > 0) {
            causesRow.style.display = 'block';
            const causeLabels = {
                tipo_anuncio: {
                    label: 'Tipo anúncio fraco',
                    icon: 'bi-star',
                    color: 'warning'
                },
                sem_frete_gratis: {
                    label: 'Sem frete grátis',
                    icon: 'bi-truck',
                    color: 'danger'
                },
                saude_baixa: {
                    label: 'Saúde baixa',
                    icon: 'bi-heart-pulse',
                    color: 'danger'
                },
                fora_catalogo: {
                    label: 'Fora do catálogo',
                    icon: 'bi-book',
                    color: 'secondary'
                },
            };
            let causesHtml = '<div class="small text-muted mb-2"><i class="bi bi-search me-1"></i>Diagnóstico das causas prováveis:</div><div class="d-flex flex-wrap gap-2">';
            Object.entries(causes).forEach(([key, count]) => {
                const info = causeLabels[key] || {
                    label: key,
                    icon: 'bi-question-circle',
                    color: 'muted'
                };
                causesHtml += `<span class="badge bg-${info.color} bg-opacity-10 text-${info.color} p-2"><i class="bi ${info.icon} me-1"></i>${info.label}: <strong>${count}</strong></span>`;
            });
            causesHtml += '</div>';
            causesRow.innerHTML = causesHtml;
        }

        // Color coding for severity
        const pctEl = document.getElementById('stalePercent');
        if (summary.stale_percent >= 30) {
            pctEl.className = 'funnel-value text-danger';
        } else if (summary.stale_percent >= 15) {
            pctEl.className = 'funnel-value text-warning';
        }

        // Impact alert
        const alertEl = document.getElementById('staleImpactAlert');
        if (summary.impact_level === 'critical' || summary.impact_level === 'warning') {
            alertEl.style.display = 'flex';
            alertEl.style.setProperty('display', 'flex', 'important');
            alertEl.className = 'alert d-flex align-items-start gap-2 mb-3 ' +
                (summary.impact_level === 'critical' ? 'alert-danger' : 'alert-warning');
            document.getElementById('staleImpactTitle').textContent =
                summary.impact_level === 'critical' ? 'Impacto crítico na conta!' : 'Atenção com sua conta';
            document.getElementById('staleImpactText').textContent = impact.recommendation || '';
        } else {
            alertEl.style.display = 'none';
            alertEl.style.setProperty('display', 'none', 'important');
        }

        // Render items
        const container = document.getElementById('staleItemsList');
        let html = '';
        const staleFallbackImg = 'data:image/svg+xml,%3Csvg xmlns=%27http://www.w3.org/2000/svg%27 width=%2764%27 height=%2764%27 fill=%27%23ccc%27%3E%3Crect width=%2764%27 height=%2764%27 fill=%27%23f0f0f0%27/%3E%3Ctext x=%2732%27 y=%2736%27 text-anchor=%27middle%27 font-size=%2712%27%3ESem img%3C/text%3E%3C/svg%3E';

        items.forEach((item, index) => {
            const hiddenClass = index >= STALE_INITIAL_SHOW ? 'stale-hidden' : '';
            const severityClass = item.severity === 'critical' ? 'severity-critical' : 'severity-warning';
            const daysLabel = item.days_active >= 365 ?
                Math.floor(item.days_active / 365) + ' ano(s)' :
                item.days_active + ' dias';
            const severityBadge = item.severity === 'critical' ?
                '<span class="badge bg-danger">Crítico</span>' :
                '<span class="badge bg-warning text-dark">Alerta</span>';

            html += `
        <div class="stale-item ${severityClass} ${hiddenClass}" data-stale-index="${index}">
              <img src="${escapeHtml(normalizeExternalUrl(item.thumbnail) || staleFallbackImg)}"
                 alt="" class="stale-item-thumb"
                  data-fallback-src="${staleFallbackImg}">
            <div class="stale-item-info">
                <div class="stale-item-title" title="${escapeHtml(item.title)}">${escapeHtml(item.title)}</div>
                <div class="stale-item-meta">
                    <span><i class="bi bi-clock me-1"></i>${daysLabel} ativo</span>
                    <span><i class="bi bi-tag me-1"></i>R$ ${formatPrice(item.price)}</span>
                    <span><i class="bi bi-box me-1"></i>Estoque: ${item.available_qty}</span>
                    ${severityBadge}
                </div>
                ${(item.causes && item.causes.length > 0) ? '<div class="d-flex flex-wrap gap-1 mt-1">' + item.causes.map(c => {
                    const causeLabels = {
                        tipo_anuncio: { label: 'Tipo anúncio', icon: 'bi-star', color: 'warning' },
                        sem_frete_gratis: { label: 'Sem frete grátis', icon: 'bi-truck', color: 'danger' },
                        saude_baixa: { label: 'Saúde baixa', icon: 'bi-heart-pulse', color: 'danger' },
                        fora_catalogo: { label: 'Fora do catálogo', icon: 'bi-book', color: 'secondary' },
                    };
                    const info = causeLabels[c] || { label: escapeHtml(c), icon: 'bi-question-circle', color: 'muted' };
                    return `<span class="badge bg-${info.color} bg-opacity-10 text-${info.color}" style="font-size:10px;"><i class="bi ${info.icon} me-1"></i>${info.label}</span>`;
                }).join('') + '</div>' : ''}
            </div>
            <div class="stale-item-actions">
                <a href="${escapeHtml(normalizeExternalUrl(item.permalink) || '#')}" target="_blank" rel="noopener" class="btn btn-sm btn-outline-primary" title="Ver no ML">
                    <i class="bi bi-box-arrow-up-right"></i>
                </a>
                <a href="/dashboard/items/${escapeHtml(item.id)}/edit" class="btn btn-sm btn-outline-warning" title="Otimizar">
                    <i class="bi bi-pencil"></i>
                </a>
            </div>
        </div>`;
        });

        container.innerHTML = html;
        bindImageFallbacks(container);

        // Show more toggle
        const showMoreWrap = document.getElementById('staleShowMoreWrap');
        if (items.length > STALE_INITIAL_SHOW) {
            showMoreWrap.style.display = 'block';
            document.getElementById('staleShowMoreText').textContent =
                `Ver mais ${items.length - STALE_INITIAL_SHOW} anúncios`;
        } else {
            showMoreWrap.style.display = 'none';
        }

        staleExpanded = false;
    }

    function toggleStaleItems() {
        staleExpanded = !staleExpanded;
        const items = document.querySelectorAll('.stale-item[data-stale-index]');
        items.forEach(el => {
            const idx = parseInt(el.getAttribute('data-stale-index'));
            if (idx >= STALE_INITIAL_SHOW) {
                el.classList.toggle('stale-hidden', !staleExpanded);
            }
        });

        const textEl = document.getElementById('staleShowMoreText');
        const hidden = document.querySelectorAll('.stale-item.stale-hidden').length;
        textEl.textContent = staleExpanded ? 'Ver menos' : `Ver mais ${hidden} anúncios`;

        const icon = document.querySelector('#staleShowMoreWrap .bi');
        if (icon) {
            icon.className = staleExpanded ? 'bi bi-chevron-up me-1' : 'bi bi-chevron-down me-1';
        }
    }

    // =========================================================================
    // QUICK ACTIONS
    // =========================================================================

    function renderQuickActions(data) {
        const section = document.getElementById('quickActionsSection');
        const container = document.getElementById('quickActionsList');
        if (!section || !container) return;

        const actions = [];
        const pillars = data.pillars || {};
        const summary = data.summary || {};
        const stale = data.stale_listings?.summary || {};

        // Perguntas sem resposta
        const unanswered = pillars.operation?.details?.unanswered_questions || 0;
        if (unanswered > 0) {
            actions.push({
                href: '/dashboard/questions',
                icon: 'bi-chat-dots',
                iconBg: 'bg-danger bg-opacity-10 text-danger',
                label: 'Responder Perguntas',
                badge: unanswered,
                badgeClass: 'bg-danger text-white',
            });
        }

        // Otimizar SEO
        const seoScore = pillars.seo_quality?.score || 0;
        if (seoScore < 70) {
            actions.push({
                href: '/seo',
                icon: 'bi-stars',
                iconBg: 'bg-warning bg-opacity-10 text-warning',
                label: 'Otimizar SEO',
                badge: null,
                badgeClass: '',
            });
        }

        // Anúncios parados (ação rápida)
        if (stale.total_stale > 0) {
            actions.push({
                href: '#staleSection',
                icon: 'bi-hourglass-bottom',
                iconBg: 'bg-danger bg-opacity-10 text-danger',
                label: 'Anúncios Parados',
                badge: stale.total_stale,
                badgeClass: 'bg-danger text-white',
            });
        }

        // Ver anúncios
        actions.push({
            href: '/dashboard/items',
            icon: 'bi-box-seam',
            iconBg: 'bg-primary bg-opacity-10 text-primary',
            label: 'Ver Anúncios',
            badge: null,
            badgeClass: '',
        });

        // Ajustar preços
        if ((pillars.competitiveness?.score || 0) < 70) {
            actions.push({
                href: '/dashboard/pricing',
                icon: 'bi-currency-dollar',
                iconBg: 'bg-success bg-opacity-10 text-success',
                label: 'Ajustar Preços',
                badge: null,
                badgeClass: '',
            });
        }

        // Ver pedidos
        const orders = pillars.operation?.details?.orders_30d || 0;
        if (orders > 0) {
            actions.push({
                href: '/dashboard/orders',
                icon: 'bi-receipt',
                iconBg: 'bg-info bg-opacity-10 text-info',
                label: 'Pedidos',
                badge: orders,
                badgeClass: 'bg-info text-white',
            });
        }

        // Adoção catálogo (se muitos fora do catálogo)
        const nonCatalog = pillars.seo_quality?.details?.non_catalog_items || 0;
        if (nonCatalog > 5) {
            actions.push({
                href: '/dashboard/items',
                icon: 'bi-book',
                iconBg: 'bg-primary bg-opacity-10 text-primary',
                label: 'Vincular Catálogo',
                badge: nonCatalog,
                badgeClass: 'bg-primary text-white',
            });
        }

        // Ticket médio caindo
        const salesIssues = pillars.sales?.issues || [];
        const ticketDeclining = salesIssues.find(i => (i.type || i.message || '').includes('ticket'));
        if (ticketDeclining) {
            actions.push({
                href: '/dashboard/pricing',
                icon: 'bi-graph-down-arrow',
                iconBg: 'bg-warning bg-opacity-10 text-warning',
                label: 'Ticket em Queda',
                badge: null,
                badgeClass: '',
            });
        }

        // Itens com estoque baixo (attention items)
        const attentionItems = data.items_attention || [];
        const lowStock = attentionItems.find(i => i.type === 'low_stock');
        if (lowStock) {
            actions.push({
                href: '/dashboard/items',
                icon: 'bi-exclamation-diamond',
                iconBg: 'bg-danger bg-opacity-10 text-danger',
                label: 'Estoque Baixo',
                badge: lowStock.count,
                badgeClass: 'bg-danger text-white',
            });
        }

        if (actions.length === 0) {
            section.style.display = 'none';
            return;
        }

        section.style.display = 'block';
        container.innerHTML = actions.map(a => {
            const badgeHtml = a.badge !== null ?
                `<span class="qa-badge ${a.badgeClass}">${a.badge}</span>` :
                '';
            return `
        <a href="${a.href}" class="quick-action-btn">
            <span class="qa-icon ${a.iconBg}"><i class="bi ${a.icon}"></i></span>
            <span>${a.label}</span>
            ${badgeHtml}
        </a>`;
        }).join('');
    }

    // =========================================================================
    // OPERATION PANEL
    // =========================================================================

    function renderOperationPanel(details, score) {
        const section = document.getElementById('operationSection');
        if (!section) return;

        if (!details || typeof details.orders_30d === 'undefined') {
            section.style.display = 'none';
            return;
        }

        section.style.display = 'block';

        // Métricas
        const orders = details.orders_30d || 0;
        const cancelled = details.cancelled_30d || 0;
        const delayRate = details.delay_rate || 0;
        const unanswered = details.unanswered_questions || 0;
        const fullEnabled = details.fulfillment_enabled || false;

        document.getElementById('opsOrders30d').textContent = formatNumber(orders);
        document.getElementById('opsCancelled30d').textContent = formatNumber(cancelled);
        document.getElementById('opsCancelled30d').className = 'ops-metric-value ' +
            (cancelled > 0 ? 'text-danger' : 'text-success');

        const delayEl = document.getElementById('opsDelayRate');
        delayEl.textContent = delayRate + '%';
        delayEl.className = 'ops-metric-value ' +
            (delayRate > 10 ? 'text-danger' : (delayRate > 5 ? 'text-warning' : 'text-success'));

        const unansweredEl = document.getElementById('opsUnanswered');
        unansweredEl.textContent = unanswered;
        unansweredEl.className = 'ops-metric-value ' +
            (unanswered > 5 ? 'text-danger' : (unanswered > 0 ? 'text-warning' : 'text-success'));

        // Novas métricas
        const opsClaimsEl = document.getElementById('opsOpenClaims');
        if (opsClaimsEl) {
            const oc = details.open_claims || 0;
            opsClaimsEl.textContent = oc;
            opsClaimsEl.className = 'ops-metric-value ' +
                (oc > 3 ? 'text-danger' : (oc > 0 ? 'text-warning' : 'text-success'));
        }

        const opsRespEl = document.getElementById('opsResponseTime');
        if (opsRespEl) {
            const avgMin = details.avg_response_time_min;
            if (avgMin !== undefined && avgMin !== null) {
                const hours = (avgMin / 60).toFixed(1);
                opsRespEl.textContent = hours + 'h';
                opsRespEl.className = 'ops-metric-value ' +
                    (avgMin > 180 ? 'text-danger' : (avgMin > 60 ? 'text-warning' : 'text-success'));
            }
        }

        // Status items + Score breakdown bars
        const statusList = document.getElementById('opsStatusList');
        let html = '';

        // Score breakdown bars
        const breakdown = details.score_breakdown || {};
        const opsBarFactors = [{
                key: 'delay',
                icon: 'bi-clock',
                color: 'info'
            },
            {
                key: 'questions',
                icon: 'bi-chat-dots',
                color: 'primary'
            },
            {
                key: 'shipping',
                icon: 'bi-truck',
                color: 'success'
            },
            {
                key: 'orders',
                icon: 'bi-x-circle',
                color: 'danger'
            },
            {
                key: 'claims',
                icon: 'bi-shield-exclamation',
                color: 'warning'
            },
            {
                key: 'compliance',
                icon: 'bi-clipboard-check',
                color: 'secondary'
            },
        ];
        if (Object.keys(breakdown).length > 0) {
            html += '<div class="mb-3">';
            opsBarFactors.forEach(f => {
                const bd = breakdown[f.key];
                if (!bd) return;
                const pct = Math.round(bd.score / bd.max * 100);
                const barColor = pct >= 70 ? 'bg-success' : (pct >= 40 ? 'bg-warning' : 'bg-danger');
                html += `
            <div class="d-flex align-items-center gap-2 mb-2">
                <i class="bi ${f.icon} text-${f.color}" style="width:20px;"></i>
                <span class="small" style="width:110px;">${bd.label}</span>
                <div class="progress flex-grow-1" style="height:6px;"><div class="progress-bar ${barColor}" style="width:${pct}%"></div></div>
                <span class="small text-muted" style="width:45px;text-align:right;">${bd.score}/${bd.max}</span>
            </div>`;
            });
            html += '</div>';
        }

        // Fulfillment
        html += `
    <div class="ops-status-row">
        <div class="ops-status-icon ${fullEnabled ? 'bg-success bg-opacity-10 text-success' : 'bg-warning bg-opacity-10 text-warning'}">
            <i class="bi ${fullEnabled ? 'bi-check-circle-fill' : 'bi-exclamation-circle'}"></i>
        </div>
        <div style="flex:1">
            <div class="fw-semibold">${fullEnabled ? 'Mercado Envios Full Ativo' : 'Full Não Ativo'}</div>
            <div class="text-muted small">${fullEnabled ? 'Entregas rápidas melhoram ranking' : 'Ative para entregas mais rápidas e melhor posicionamento'}</div>
        </div>
        ${!fullEnabled ? '<a href="https://www.mercadolivre.com.br/ajuda/16605" target="_blank" class="btn btn-sm btn-outline-success">Ativar</a>' : ''}
    </div>`;

        // Cancelamento
        const cancelPct = orders > 0 ? ((cancelled / orders) * 100).toFixed(1) : 0;
        const cancelOk = cancelPct <= 2;
        html += `
    <div class="ops-status-row">
        <div class="ops-status-icon ${cancelOk ? 'bg-success bg-opacity-10 text-success' : 'bg-danger bg-opacity-10 text-danger'}">
            <i class="bi ${cancelOk ? 'bi-shield-check' : 'bi-x-circle'}"></i>
        </div>
        <div style="flex:1">
            <div class="fw-semibold">Taxa de Cancelamento: ${cancelPct}%</div>
            <div class="text-muted small">${cancelOk ? 'Dentro do ideal (&le;2%)' : 'Acima do ideal — investigue as causas'}</div>
        </div>
    </div>`;

        // Perguntas
        const qOk = unanswered === 0;
        html += `
    <div class="ops-status-row">
        <div class="ops-status-icon ${qOk ? 'bg-success bg-opacity-10 text-success' : 'bg-warning bg-opacity-10 text-warning'}">
            <i class="bi ${qOk ? 'bi-chat-check' : 'bi-chat-dots'}"></i>
        </div>
        <div style="flex:1">
            <div class="fw-semibold">${qOk ? 'Todas as perguntas respondidas' : unanswered + ' perguntas pendentes'}</div>
            <div class="text-muted small">${qOk ? 'Excelente! Continue respondendo rápido' : 'Responda rápido — cada pergunta é uma venda potencial'}</div>
        </div>
        ${!qOk ? '<a href="/dashboard/questions" class="btn btn-sm btn-outline-primary">Responder</a>' : ''}
    </div>`;

        // Tempo de resposta a perguntas
        const avgResponse = details.avg_response_time_min;
        if (avgResponse !== undefined && avgResponse !== null) {
            const responseHours = (avgResponse / 60).toFixed(1);
            const responseOk = avgResponse <= 60;
            html += `
    <div class="ops-status-row">
        <div class="ops-status-icon ${responseOk ? 'bg-success bg-opacity-10 text-success' : 'bg-warning bg-opacity-10 text-warning'}">
            <i class="bi ${responseOk ? 'bi-stopwatch' : 'bi-hourglass-split'}"></i>
        </div>
        <div style="flex:1">
            <div class="fw-semibold">Tempo médio de resposta: ${responseHours}h</div>
            <div class="text-muted small">${responseOk ? 'Bom tempo de resposta (ideal < 1h)' : 'Tente responder mais rápido — compradores compram de quem responde primeiro'}</div>
        </div>
    </div>`;
        }

        // Mediações / Claims
        const openClaims = details.open_claims || 0;
        const totalClaims = details.total_claims || 0;
        const claimsAgainst = details.claims_against_seller || 0;
        if (totalClaims > 0 || openClaims > 0) {
            const claimOk = openClaims === 0;
            html += `
    <div class="ops-status-row">
        <div class="ops-status-icon ${claimOk ? 'bg-success bg-opacity-10 text-success' : 'bg-danger bg-opacity-10 text-danger'}">
            <i class="bi ${claimOk ? 'bi-shield-check' : 'bi-shield-exclamation'}"></i>
        </div>
        <div style="flex:1">
            <div class="fw-semibold">${claimOk ? 'Sem mediações abertas' : openClaims + ' mediações abertas'}</div>
            <div class="text-muted small">Total recente: ${totalClaims} | Decididas contra: ${claimsAgainst}${details.top_claim_reason ? ' | Principal motivo: ' + escapeHtml(details.top_claim_reason) : ''}</div>
        </div>
    </div>`;
        }

        // Conformidade (itens em revisão / inativos)
        const underReview = details.under_review_items || 0;
        const inactiveItems = details.inactive_items || 0;
        if (underReview > 0 || inactiveItems > 10) {
            const complianceOk = underReview === 0;
            html += `
    <div class="ops-status-row">
        <div class="ops-status-icon ${complianceOk ? 'bg-info bg-opacity-10 text-info' : 'bg-danger bg-opacity-10 text-danger'}">
            <i class="bi ${complianceOk ? 'bi-clipboard-check' : 'bi-exclamation-octagon'}"></i>
        </div>
        <div style="flex:1">
            <div class="fw-semibold">${underReview > 0 ? underReview + ' anúncios em revisão (infrações)' : 'Nenhuma infração pendente'}</div>
            <div class="text-muted small">${inactiveItems > 0 ? inactiveItems + ' inativos no catálogo' : ''} ${underReview > 0 ? '— verifique a Central do Vendedor' : ''}</div>
        </div>
    </div>`;
        }

        statusList.innerHTML = html;
    }

    function renderCrossInsights(insights) {
        const section = document.getElementById('crossInsightsSection');
        if (!section) return;
        if (!insights || insights.length === 0) {
            section.style.display = 'none';
            return;
        }
        section.style.display = 'block';
        document.getElementById('insightsCount').textContent = insights.length;

        const container = document.getElementById('crossInsightsList');
        let html = '';
        insights.forEach(insight => {
            const pillarsHtml = (insight.pillars || []).map(p => {
                const names = {
                    reputation: 'Reputação',
                    seo_quality: 'SEO',
                    competitiveness: 'Competitividade',
                    operation: 'Operação',
                    sales: 'Vendas'
                };
                return `<span class="badge bg-light text-dark border">${escapeHtml(names[p] || p)}</span>`;
            }).join(' ');
            html += `
        <div class="d-flex align-items-start gap-3 p-3 border rounded mb-2">
            <div class="flex-shrink-0 mt-1"><i class="bi ${insight.icon} text-${insight.color} fs-4"></i></div>
            <div style="flex:1">
                <div class="fw-semibold mb-1">${escapeHtml(insight.title)}</div>
                <div class="text-muted small mb-2">${escapeHtml(insight.message)}</div>
                <div class="d-flex gap-1">${pillarsHtml}</div>
            </div>
        </div>`;
        });
        container.innerHTML = html;
    }

    function renderDataSourcesPanel(sources) {
        const section = document.getElementById('dataSourcesSection');
        if (!section) return;
        if (!sources) {
            section.style.display = 'none';
            return;
        }
        section.style.display = 'block';

        const mlSignals = sources.ml_signals || [];
        const localAnalysis = sources.local_analysis || [];
        const composition = sources.score_composition || {};

        const badge = document.getElementById('dataSourcesBadge');
        const realCount = mlSignals.filter(s => s.type === 'real').length;
        badge.textContent = `${realCount} sinais ML reais`;

        let html = '<div class="row g-2">';

        // ML Signals
        html += '<div class="col-12 col-md-8"><h6 class="fw-semibold text-success mb-2"><i class="bi bi-patch-check-fill me-1"></i>Sinais Reais do Mercado Livre</h6>';
        html += '<div class="d-flex flex-wrap gap-1 mb-3">';
        mlSignals.forEach(s => {
            const typeClass = s.type === 'real' ? 'bg-success-subtle text-success' : 'bg-warning-subtle text-warning';
            const icon = s.type === 'real' ? 'bi-check-circle-fill' : 'bi-exclamation-circle';
            html += `<span class="badge ${typeClass} fw-normal" title="${escapeHtml(s.source)}"><i class="bi ${icon} me-1"></i>${escapeHtml(s.name)}</span>`;
        });
        html += '</div></div>';

        // Local Analysis
        html += '<div class="col-12 col-md-4"><h6 class="fw-semibold text-secondary mb-2"><i class="bi bi-calculator me-1"></i>Análise Local (Heurística)</h6>';
        html += '<div class="d-flex flex-wrap gap-1 mb-3">';
        localAnalysis.forEach(s => {
            html += `<span class="badge bg-secondary-subtle text-secondary fw-normal"><i class="bi bi-cpu me-1"></i>${escapeHtml(s.name)}</span>`;
        });
        html += '</div></div>';

        // Composition
        html += '<div class="col-12">';
        const mlW = composition.ml_weight || 0;
        const localW = composition.local_weight || 100;
        html += `<div class="d-flex align-items-center gap-2 small text-muted">`;
        html += `<i class="bi bi-pie-chart me-1"></i>`;
        html += `<span>Score SEO/Qualidade: <strong class="text-success">${mlW}% ML oficial</strong> + <strong>${localW}% análise local</strong></span>`;
        html += `</div>`;
        html += '</div>';

        html += '</div>';

        document.getElementById('dataSourcesList').innerHTML = html;
    }

    function renderSalesSection(details) {
        const section = document.getElementById('salesSection');
        if (!section) return;

        if (!details.sales_30d && details.sales_30d !== 0) {
            section.style.display = 'none';
            return;
        }

        section.style.display = 'block';

        // Metrics
        document.getElementById('salesVisits30d').textContent = formatNumber(details.visits_30d || 0);
        document.getElementById('salesCount30d').textContent = formatNumber(details.sales_30d || 0);
        document.getElementById('revenue30d').textContent = 'R$ ' + formatPrice(details.revenue_30d || 0);
        document.getElementById('avgTicket').textContent = 'R$ ' + formatPrice(details.avg_ticket || 0);

        // Ticket compare
        const ticketCompareEl = document.getElementById('avgTicketCompare');
        if (details.sales_prev_30d > 0 && details.revenue_prev_30d > 0) {
            const prevTicket = details.revenue_prev_30d / details.sales_prev_30d;
            const ticketDiff = (details.avg_ticket || 0) - prevTicket;
            const tIcon = ticketDiff > 0 ? 'bi-arrow-up' : (ticketDiff < 0 ? 'bi-arrow-down' : 'bi-dash');
            const tClr = ticketDiff > 0 ? 'text-success' : (ticketDiff < 0 ? 'text-danger' : 'text-muted');
            ticketCompareEl.innerHTML = `<span class="${tClr}"><i class="bi ${tIcon}"></i> R$ ${formatPrice(Math.abs(ticketDiff))}</span>`;
        }

        // Revenue per item
        const rpiEl = document.getElementById('revenuePerItem');
        if (details.revenue_per_item !== undefined) {
            rpiEl.textContent = 'R$ ' + formatPrice(details.revenue_per_item);
        }

        // Growth
        const growth = details.sales_growth || 0;
        const growthEl = document.getElementById('salesGrowth');
        growthEl.textContent = (growth > 0 ? '+' : '') + growth + '%';
        growthEl.className = 'funnel-value ' + (growth > 0 ? 'text-success' : (growth < 0 ? 'text-danger' : 'text-muted'));

        // Conversion rate
        const convEl = document.getElementById('conversionRate');
        const convRate = details.conversion_rate || 0;
        convEl.textContent = convRate + '%';
        convEl.className = 'funnel-value ' + (convRate >= 3 ? 'text-success' : (convRate >= 1 ? 'text-warning' : 'text-danger'));

        // Revenue growth
        const revGrowth = details.revenue_growth || 0;
        const revGrowthEl = document.getElementById('revenueGrowth');
        revGrowthEl.textContent = (revGrowth > 0 ? '+' : '') + revGrowth + '%';
        revGrowthEl.className = 'funnel-value ' + (revGrowth > 0 ? 'text-success' : (revGrowth < 0 ? 'text-danger' : 'text-muted'));

        // Comparison indicators
        const salesCompareEl = document.getElementById('salesCount30dCompare');
        if (details.sales_prev_30d !== undefined) {
            const diff = (details.sales_30d || 0) - (details.sales_prev_30d || 0);
            const icon = diff > 0 ? 'bi-arrow-up' : (diff < 0 ? 'bi-arrow-down' : 'bi-dash');
            const clr = diff > 0 ? 'text-success' : (diff < 0 ? 'text-danger' : 'text-muted');
            salesCompareEl.innerHTML = `<span class="${clr}"><i class="bi ${icon}"></i> ${Math.abs(diff)} vs período anterior</span>`;
        }

        const revCompareEl = document.getElementById('revenue30dCompare');
        if (details.revenue_prev_30d !== undefined) {
            const diff = (details.revenue_30d || 0) - (details.revenue_prev_30d || 0);
            const icon = diff > 0 ? 'bi-arrow-up' : (diff < 0 ? 'bi-arrow-down' : 'bi-dash');
            const clr = diff > 0 ? 'text-success' : (diff < 0 ? 'text-danger' : 'text-muted');
            revCompareEl.innerHTML = `<span class="${clr}"><i class="bi ${icon}"></i> R$ ${formatPrice(Math.abs(diff))}</span>`;
        }

        // Sales comparison chart (current vs previous)
        renderSalesChart(details);
    }

    function renderSalesChart(details) {
        if (typeof Chart === 'undefined') return;
        // Chart 1: Vendas (unidades)
        const ctxUnits = document.getElementById('salesUnitsChart');
        if (ctxUnits) {
            if (salesUnitsChartInstance) salesUnitsChartInstance.destroy();
            salesUnitsChartInstance = new Chart(ctxUnits, {
                type: 'bar',
                data: {
                    labels: ['Últimos 30d', '30d Anteriores'],
                    datasets: [{
                        data: [details.sales_30d || 0, details.sales_prev_30d || 0],
                        backgroundColor: ['rgba(34, 197, 94, 0.8)', 'rgba(156, 163, 175, 0.5)'],
                        borderRadius: 8,
                        barPercentage: 0.6,
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: {
                            display: false
                        },
                        tooltip: {
                            callbacks: {
                                label: (ctx) => ctx.parsed.y + ' vendas'
                            }
                        }
                    },
                    scales: {
                        y: {
                            beginAtZero: true,
                            grid: {
                                color: 'rgba(0,0,0,0.05)'
                            },
                            ticks: {
                                precision: 0
                            }
                        },
                        x: {
                            grid: {
                                display: false
                            }
                        }
                    }
                }
            });
        }

        // Chart 2: Receita (R$)
        const ctxRev = document.getElementById('salesRevenueChart');
        if (ctxRev) {
            if (salesRevenueChartInstance) salesRevenueChartInstance.destroy();
            salesRevenueChartInstance = new Chart(ctxRev, {
                type: 'bar',
                data: {
                    labels: ['Últimos 30d', '30d Anteriores'],
                    datasets: [{
                        data: [details.revenue_30d || 0, details.revenue_prev_30d || 0],
                        backgroundColor: ['rgba(59, 130, 246, 0.8)', 'rgba(156, 163, 175, 0.5)'],
                        borderRadius: 8,
                        barPercentage: 0.6,
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: {
                            display: false
                        },
                        tooltip: {
                            callbacks: {
                                label: (ctx) => 'R$ ' + formatPrice(ctx.parsed.y)
                            }
                        }
                    },
                    scales: {
                        y: {
                            beginAtZero: true,
                            grid: {
                                color: 'rgba(0,0,0,0.05)'
                            },
                            ticks: {
                                callback: (v) => 'R$ ' + (v >= 1000 ? (v / 1000).toFixed(0) + 'k' : v)
                            }
                        },
                        x: {
                            grid: {
                                display: false
                            }
                        }
                    }
                }
            });
        }
    }

    // ===================================================================
    // REPUTATION PANEL
    // ===================================================================

    function renderReputationPanel(details) {
        const section = document.getElementById('reputationSection');
        if (!section) return;

        if (!details.level_id && !details.total_sales) {
            section.style.display = 'none';
            return;
        }

        section.style.display = 'block';

        // Level badge
        const levelBadge = document.getElementById('repLevelBadge');
        const levelInfo = getMLLevelInfo(details.level_id);
        levelBadge.className = 'rep-level-badge ' + levelInfo.cssClass;
        levelBadge.innerHTML = `<i class="bi ${levelInfo.icon}"></i> ${levelInfo.label}`;

        // Power seller
        const powerEl = document.getElementById('repPowerSeller');
        if (details.power_seller) {
            const psLabels = {
                platinum: 'MercadoLíder Platinum',
                gold: 'MercadoLíder Gold',
                silver: 'MercadoLíder'
            };
            powerEl.innerHTML = `<span class="badge bg-warning text-dark"><i class="bi bi-trophy me-1"></i>${psLabels[details.power_seller] || details.power_seller}</span>`;
        } else {
            powerEl.innerHTML = '<span class="text-muted small">Não é MercadoLíder</span>';
        }

        // Metrics
        document.getElementById('repSalesTotal').textContent = formatNumber(details.total_sales || 0);
        document.getElementById('repCancelledTotal').textContent = formatNumber(details.total_canceled || 0);
        document.getElementById('repClaimsRate').textContent = (details.claims_rate || 0) + '%';
        document.getElementById('repCancelRate').textContent = (details.cancellations_rate || 0) + '%';
        document.getElementById('repDelayRate').textContent = (details.delayed_rate || 0) + '%';

        // Color the metrics
        colorMetric('repClaimsRate', details.claims_rate, 3, 5);
        colorMetric('repCancelRate', details.cancellations_rate, 3, 5);
        colorMetric('repDelayRate', details.delayed_rate, 5, 15);

        // Ratings donut chart
        const positivePct = details.positive_pct || 0;
        document.getElementById('ratingsPositivePct').textContent = positivePct + '%';

        renderRatingsChart(
            details.positive_ratings || 0,
            details.neutral_ratings || 0,
            details.negative_ratings || 0
        );
    }

    function renderRatingsChart(positive, neutral, negative) {
        const ctx = document.getElementById('ratingsChart');
        if (!ctx || typeof Chart === 'undefined') return;

        if (ratingsChartInstance) {
            ratingsChartInstance.destroy();
        }

        ratingsChartInstance = new Chart(ctx, {
            type: 'doughnut',
            data: {
                labels: ['Positivas', 'Neutras', 'Negativas'],
                datasets: [{
                    data: [positive, neutral, negative],
                    backgroundColor: ['#22c55e', '#f59e0b', '#ef4444'],
                    borderWidth: 0,
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: true,
                cutout: '70%',
                plugins: {
                    legend: {
                        display: false
                    },
                    tooltip: {
                        enabled: true
                    }
                }
            }
        });
    }

    function getMLLevelInfo(levelId) {
        if (!levelId) return {
            label: 'Desconhecido',
            icon: 'bi-question-circle',
            cssClass: 'rep-level-1'
        };

        if (levelId.includes('5_green')) return {
            label: 'Verde (Nível 5)',
            icon: 'bi-shield-fill-check',
            cssClass: 'rep-level-5'
        };
        if (levelId.includes('4_light')) return {
            label: 'Verde Claro (Nível 4)',
            icon: 'bi-shield-check',
            cssClass: 'rep-level-4'
        };
        if (levelId.includes('3_yellow')) return {
            label: 'Amarelo (Nível 3)',
            icon: 'bi-shield-exclamation',
            cssClass: 'rep-level-3'
        };
        if (levelId.includes('2_orange')) return {
            label: 'Laranja (Nível 2)',
            icon: 'bi-shield',
            cssClass: 'rep-level-2'
        };
        return {
            label: 'Vermelho (Nível 1)',
            icon: 'bi-shield-x',
            cssClass: 'rep-level-1'
        };
    }

    function colorMetric(elementId, value, warnThreshold, critThreshold) {
        const el = document.getElementById(elementId);
        if (!el) return;
        const v = parseFloat(value) || 0;
        if (v >= critThreshold) {
            el.classList.add('text-danger');
        } else if (v >= warnThreshold) {
            el.classList.add('text-warning');
        } else {
            el.classList.add('text-success');
        }
    }

    // ===================================================================
    // INTERACTIONS
    // ===================================================================

    function filterActions(severity, btn) {
        currentFilter = severity;

        // Toggle active button
        btn?.closest('.btn-group')?.querySelectorAll('.btn').forEach(b => b.classList.remove('active'));
        btn?.classList.add('active');

        let visibleCount = 0;
        document.querySelectorAll('.action-item').forEach(item => {
            const matchesSeverity = severity === 'all' || item.dataset.severity === severity;
            if (matchesSeverity) {
                item.style.display = '';
                visibleCount++;
            } else {
                item.style.display = 'none';
            }
        });

        // Atualizar badge de contagem
        const countEl = document.getElementById('actionCount');
        if (countEl) {
            const totalActions = diagnosticData?.action_items?.length || 0;
            countEl.textContent = severity === 'all' ? totalActions : visibleCount;
        }

        updateCompletedCounter();
    }

    function showPillarDetail(pillarKey) {
        if (!diagnosticData?.pillars?.[pillarKey]) return;

        const p = diagnosticData.pillars[pillarKey];
        const issues = p.issues || [];
        const details = p.details || {};

        let issuesHtml = issues.length === 0 ?
            '<p class="text-success"><i class="bi bi-check-circle me-1"></i>Nenhum problema encontrado!</p>' :
            issues.map(i => `
            <div class="action-item severity-${i.severity} mb-2">
                <div class="fw-semibold">${escapeHtml(i.message)}</div>
                <div class="text-muted small">${escapeHtml(i.impact)}</div>
                <div class="text-primary small"><strong>Ação:</strong> ${escapeHtml(i.action)}</div>
            </div>
        `).join('');

        // Per-pillar specific details
        let detailsHtml = '';
        if (pillarKey === 'seo_quality') {
            const mlq = details.ml_quality;
            const mlQualityHtml = mlq ? `
        <div class="mb-3">
            <h6 class="fw-semibold mb-2"><i class="bi bi-patch-check text-primary me-1"></i>Qualidade Oficial ML</h6>
            <div class="row g-1">
                <div class="col-3"><div class="rep-metric-card"><div class="rep-metric-value text-success">${mlq.excellent || 0}</div><div class="rep-metric-label">Excelente</div></div></div>
                <div class="col-3"><div class="rep-metric-card"><div class="rep-metric-value text-primary">${mlq.good || 0}</div><div class="rep-metric-label">Bom</div></div></div>
                <div class="col-3"><div class="rep-metric-card"><div class="rep-metric-value text-warning">${mlq.regular || 0}</div><div class="rep-metric-label">Regular</div></div></div>
                <div class="col-3"><div class="rep-metric-card"><div class="rep-metric-value text-danger">${mlq.poor || 0}</div><div class="rep-metric-label">Ruim</div></div></div>
            </div>
            <div class="text-muted small mt-1"><i class="bi bi-info-circle me-1"></i>Fonte: API ML /users/listings_quality (score oficial)</div>
        </div>` : '';
            const catalogHtml = details.catalog_items !== undefined ? `
        <div class="row g-2 mb-3">
            <div class="col-4"><div class="rep-metric-card"><div class="rep-metric-value text-success">${details.catalog_items || 0}</div><div class="rep-metric-label">No Catálogo</div></div></div>
            <div class="col-4"><div class="rep-metric-card"><div class="rep-metric-value text-warning">${details.non_catalog_items || 0}</div><div class="rep-metric-label">Fora Catálogo</div></div></div>
            <div class="col-4"><div class="rep-metric-card"><div class="rep-metric-value">${details.catalog_pct || 0}%</div><div class="rep-metric-label">Adoção</div></div></div>
        </div>` : '';
            const specsHtml = details.incomplete_specs > 0 ? `
        <div class="d-flex align-items-center gap-2 small p-2 border rounded border-warning mb-2"><i class="bi bi-exclamation-triangle text-warning"></i><span>Ficha técnica incompleta: <strong>${details.incomplete_specs}</strong> anúncios (sinal ML)</span></div>` : '';
            detailsHtml = `
        <div class="row g-2 mb-3">
            <div class="col-4"><div class="rep-metric-card"><div class="rep-metric-value">${details.total_active || 0}</div><div class="rep-metric-label">Anúncios Ativos</div></div></div>
            <div class="col-4"><div class="rep-metric-card"><div class="rep-metric-value">${details.analyzed || 0}</div><div class="rep-metric-label">Analisados</div></div></div>
            <div class="col-4"><div class="rep-metric-card"><div class="rep-metric-value text-danger">${details.items_below_70 || 0}</div><div class="rep-metric-label">Score &lt; 70</div></div></div>
        </div>
        ${mlQualityHtml}
        ${catalogHtml}
        ${specsHtml}
        <div class="row g-2 mb-3">
            <div class="col-6 col-md-4"><div class="d-flex align-items-center gap-2 small p-2 border rounded"><i class="bi bi-fonts text-primary"></i><span>Títulos fracos: <strong>${details.title_issues || 0}</strong></span></div></div>
            <div class="col-6 col-md-4"><div class="d-flex align-items-center gap-2 small p-2 border rounded"><i class="bi bi-image text-success"></i><span>Poucas fotos: <strong>${details.image_issues || 0}</strong></span></div></div>
            <div class="col-6 col-md-4"><div class="d-flex align-items-center gap-2 small p-2 border rounded"><i class="bi bi-card-list text-warning"></i><span>Atributos: <strong>${details.attribute_issues || 0}</strong></span></div></div>
            <div class="col-6 col-md-4"><div class="d-flex align-items-center gap-2 small p-2 border rounded"><i class="bi bi-text-paragraph text-info"></i><span>Descrições: <strong>${details.description_issues || 0}</strong></span></div></div>
            <div class="col-6 col-md-4"><div class="d-flex align-items-center gap-2 small p-2 border rounded"><i class="bi bi-truck text-danger"></i><span>Sem frete grátis: <strong>${details.shipping_issues || 0}</strong></span></div></div>
        </div>
        ${details.score_source === 'ml_quality_60_local_40'
            ? '<div class="text-muted small mb-2"><i class="bi bi-shield-check text-success me-1"></i>Score pilar: 60% ML oficial + 40% análise local &middot; Score item: Título 20 + Imagens(ML) 20 + Atributos(categoria) 20 + Descrição 10 + Envio 20 + Bônus ML 10</div>'
            : '<div class="text-muted small mb-2"><i class="bi bi-info-circle me-1"></i>Score: análise local (ML quality indisponível) &middot; Título 20 + Imagens(ML) 20 + Atributos(categoria) 20 + Descrição 10 + Envio 20 + Bônus ML 10</div>'}
        <a href="/dashboard/seo-killer" class="btn btn-primary btn-sm"><i class="bi bi-fire me-1"></i>Otimizar com IA</a>
        <a href="/dashboard/items" class="btn btn-outline-primary btn-sm ms-1"><i class="bi bi-box-seam me-1"></i>Ver Anúncios</a>`;
        } else if (pillarKey === 'competitiveness') {
            const goodPct = details.analyzed > 0 ? Math.round((details.good_items || 0) / details.analyzed * 100) : 0;
            const catalogCompHtml = details.catalog_items !== undefined ? `
        <div class="col-6 col-md-4"><div class="d-flex align-items-center gap-2 small p-2 border rounded"><i class="bi bi-book text-primary"></i><span>No catálogo: <strong>${details.catalog_items || 0}</strong> (${details.catalog_pct || 0}%)</span></div></div>` : '';
            const draggedHtml = details.dragged_visits_items > 0 ? `
        <div class="col-6 col-md-4"><div class="d-flex align-items-center gap-2 small p-2 border rounded border-success"><i class="bi bi-graph-up text-success"></i><span>Com tração: <strong>${details.dragged_visits_items}</strong></span></div></div>` : '';
            const bestSellerHtml = details.best_seller_candidates > 0 ? `
        <div class="col-6 col-md-4"><div class="d-flex align-items-center gap-2 small p-2 border rounded border-warning"><i class="bi bi-trophy text-warning"></i><span>Candidatos best seller: <strong>${details.best_seller_candidates}</strong></span></div></div>` : '';
            detailsHtml = `
        <div class="row g-2 mb-3">
            <div class="col-4"><div class="rep-metric-card"><div class="rep-metric-value">${details.analyzed || 0}</div><div class="rep-metric-label">Analisados</div></div></div>
            <div class="col-4"><div class="rep-metric-card"><div class="rep-metric-value text-success">${details.good_items || 0}</div><div class="rep-metric-label">Competitivos</div></div></div>
            <div class="col-4"><div class="rep-metric-card"><div class="rep-metric-value">${goodPct}%</div><div class="rep-metric-label">Taxa Boa</div></div></div>
        </div>
        <div class="row g-2 mb-3">
            <div class="col-6 col-md-4"><div class="d-flex align-items-center gap-2 small p-2 border rounded"><i class="bi bi-truck text-danger"></i><span>Sem frete grátis: <strong>${details.no_free_shipping || 0}</strong></span></div></div>
            <div class="col-6 col-md-4"><div class="d-flex align-items-center gap-2 small p-2 border rounded"><i class="bi bi-star text-warning"></i><span>Não Premium: <strong>${details.no_gold_pro || 0}</strong></span></div></div>
            <div class="col-6 col-md-4"><div class="d-flex align-items-center gap-2 small p-2 border rounded"><i class="bi bi-heart-pulse text-danger"></i><span>Saúde baixa: <strong>${details.low_health || 0}</strong></span></div></div>
            ${catalogCompHtml}
            ${draggedHtml}
            ${bestSellerHtml}
        </div>
        <div class="text-muted small mb-2 mt-3"><i class="bi bi-info-circle me-1"></i>Score item: Tipo Anúncio 15 + Frete 15 + Logística 15 + Saúde ML 15 + Catálogo 10 + Qualidade(tags) 15 + Vendas 10 + Relevância 5 = 100</div>
        <a href="/dashboard/items" class="btn btn-primary btn-sm"><i class="bi bi-box-seam me-1"></i>Ver Anúncios</a>
        <a href="/dashboard/seo-killer" class="btn btn-outline-primary btn-sm ms-1"><i class="bi bi-fire me-1"></i>SEO Killer</a>`;
        } else if (pillarKey === 'operation') {
            // Score breakdown bars
            let opsBreakdownHtml = '';
            const breakdown = details.score_breakdown || {};
            const opsFactors = [{
                    key: 'delay',
                    icon: 'bi-clock',
                    color: 'info'
                },
                {
                    key: 'questions',
                    icon: 'bi-chat-dots',
                    color: 'primary'
                },
                {
                    key: 'shipping',
                    icon: 'bi-truck',
                    color: 'success'
                },
                {
                    key: 'orders',
                    icon: 'bi-x-circle',
                    color: 'danger'
                },
            ];
            opsFactors.forEach(f => {
                const bd = breakdown[f.key];
                if (!bd) return;
                const pct = Math.round(bd.score / bd.max * 100);
                const barColor = pct >= 70 ? 'bg-success' : (pct >= 40 ? 'bg-warning' : 'bg-danger');
                opsBreakdownHtml += `
            <div class="d-flex align-items-center gap-2 mb-2">
                <i class="bi ${f.icon} text-${f.color}" style="width:20px;"></i>
                <span class="small" style="width:110px;">${bd.label}</span>
                <div class="progress flex-grow-1" style="height:8px;"><div class="progress-bar ${barColor}" style="width:${pct}%"></div></div>
                <span class="small fw-bold" style="width:50px;text-align:right;">${bd.score}/${bd.max}</span>
            </div>`;
            });
            detailsHtml = `
        <div class="row g-2 mb-3">
            <div class="col-6 col-md-3"><div class="rep-metric-card"><div class="rep-metric-value">${details.orders_30d || 0}</div><div class="rep-metric-label">Pedidos 30d</div></div></div>
            <div class="col-6 col-md-3"><div class="rep-metric-card"><div class="rep-metric-value">${details.unanswered_questions || 0}</div><div class="rep-metric-label">Perguntas s/ Resp.</div></div></div>
            <div class="col-6 col-md-3"><div class="rep-metric-card"><div class="rep-metric-value">${details.delay_rate || 0}%</div><div class="rep-metric-label">Taxa Atrasos</div></div></div>
            <div class="col-6 col-md-3"><div class="rep-metric-card"><div class="rep-metric-value">${details.fulfillment_enabled ? '<span class="text-success">Sim</span>' : '<span class="text-danger">Não</span>'}</div><div class="rep-metric-label">Full Ativo</div></div></div>
        </div>
        ${opsBreakdownHtml ? '<h6 class="fw-semibold small mb-2">Composição do Score</h6>' + opsBreakdownHtml : ''}
        ${details.cancel_rate > 0 ? '<div class="small text-danger mb-2"><i class="bi bi-exclamation-triangle me-1"></i>Taxa de cancelamento: ' + details.cancel_rate + '%</div>' : ''}
        <a href="/dashboard/questions" class="btn btn-primary btn-sm"><i class="bi bi-chat-dots me-1"></i>Responder Perguntas</a>
        <a href="/dashboard/orders" class="btn btn-outline-primary btn-sm ms-1"><i class="bi bi-box-seam me-1"></i>Ver Pedidos</a>`;
        } else if (pillarKey === 'reputation') {
            const levelInfo = details.level_id ? getMLLevelInfo(details.level_id) : {
                label: 'Desconhecido',
                icon: 'bi-question-circle',
                cssClass: ''
            };
            const psLabels = {
                platinum: 'MercadoLíder Platinum',
                gold: 'MercadoLíder Gold',
                silver: 'MercadoLíder'
            };
            const psHtml = details.power_seller ?
                `<span class="badge bg-warning text-dark"><i class="bi bi-trophy me-1"></i>${psLabels[details.power_seller] || details.power_seller}</span>` :
                '<span class="text-muted small">Não é MercadoLíder</span>';

            // Score breakdown bars (like operation)
            let repBreakdownHtml = '';
            const repBd = details.score_breakdown || {};
            const repFactors = [{
                    key: 'level',
                    icon: 'bi-award',
                    color: 'success'
                },
                {
                    key: 'power_seller',
                    icon: 'bi-trophy',
                    color: 'warning'
                },
                {
                    key: 'claims',
                    icon: 'bi-exclamation-triangle',
                    color: 'danger'
                },
                {
                    key: 'cancellations',
                    icon: 'bi-x-circle',
                    color: 'danger'
                },
                {
                    key: 'delays',
                    icon: 'bi-clock',
                    color: 'info'
                },
            ];
            repFactors.forEach(f => {
                const bd = repBd[f.key];
                if (!bd) return;
                const pct = Math.round(bd.score / bd.max * 100);
                const barColor = pct >= 70 ? 'bg-success' : (pct >= 40 ? 'bg-warning' : 'bg-danger');
                repBreakdownHtml += `
            <div class="d-flex align-items-center gap-2 mb-2">
                <i class="bi ${f.icon} text-${f.color}" style="width:20px;"></i>
                <span class="small" style="width:130px;">${bd.label}</span>
                <div class="progress flex-grow-1" style="height:8px;"><div class="progress-bar ${barColor}" style="width:${pct}%"></div></div>
                <span class="small fw-bold" style="width:50px;text-align:right;">${bd.score}/${bd.max}</span>
            </div>`;
            });

            detailsHtml = `
        <div class="row g-2 mb-3">
            <div class="col-6 col-md-3"><div class="rep-metric-card"><div class="rep-metric-value"><i class="bi ${levelInfo.icon}"></i></div><div class="rep-metric-label">${levelInfo.label}</div></div></div>
            <div class="col-6 col-md-3"><div class="rep-metric-card"><div class="rep-metric-value">${psHtml}</div><div class="rep-metric-label">Power Seller</div></div></div>
            <div class="col-6 col-md-3"><div class="rep-metric-card"><div class="rep-metric-value">${details.claims_rate || 0}%</div><div class="rep-metric-label">Taxa Reclamações</div></div></div>
            <div class="col-6 col-md-3"><div class="rep-metric-card"><div class="rep-metric-value">${details.cancellations_rate || 0}%</div><div class="rep-metric-label">Taxa Cancelamento</div></div></div>
        </div>
        ${repBreakdownHtml ? '<h6 class="fw-semibold small mb-2">Composição do Score</h6>' + repBreakdownHtml : ''}
        <div class="row g-2 mb-3">
            <div class="col-6 col-md-4"><div class="d-flex align-items-center gap-2 small p-2 border rounded"><i class="bi bi-chat-left-text text-primary"></i><span>Avaliações negativas: <strong>${details.negative_ratings || 0}</strong></span></div></div>
            <div class="col-6 col-md-4"><div class="d-flex align-items-center gap-2 small p-2 border rounded"><i class="bi bi-bag-check text-success"></i><span>Total vendas: <strong>${formatNumber(details.total_sales || 0)}</strong></span></div></div>
            <div class="col-6 col-md-4"><div class="d-flex align-items-center gap-2 small p-2 border rounded"><i class="bi bi-clock text-warning"></i><span>Taxa atraso: <strong>${details.delayed_rate || 0}%</strong></span></div></div>
        </div>
        <div class="text-muted small mb-2"><i class="bi bi-info-circle me-1"></i>Score: Nível 40 + MercadoLíder 20 + Reclamações 15 + Cancelamentos 15 + Atrasos 10 = 100</div>
        <a href="https://www.mercadolivre.com.br/reputacao" target="_blank" class="btn btn-primary btn-sm"><i class="bi bi-award me-1"></i>Ver Reputação no ML</a>`;
        } else if (pillarKey === 'sales') {
            const growthIcon = (details.sales_growth || 0) >= 0 ? 'bi-arrow-up' : 'bi-arrow-down';
            const growthColor = (details.sales_growth || 0) >= 0 ? 'text-success' : 'text-danger';
            const revGrowthIcon = (details.revenue_growth || 0) >= 0 ? 'bi-arrow-up' : 'bi-arrow-down';
            const revGrowthColor = (details.revenue_growth || 0) >= 0 ? 'text-success' : 'text-danger';
            detailsHtml = `
        <div class="row g-2 mb-3">
            <div class="col-6 col-md-3"><div class="rep-metric-card"><div class="rep-metric-value">${details.sales_30d || 0}</div><div class="rep-metric-label">Vendas 30d</div></div></div>
            <div class="col-6 col-md-3"><div class="rep-metric-card"><div class="rep-metric-value">R$ ${formatPrice(details.revenue_30d || 0)}</div><div class="rep-metric-label">Receita 30d</div></div></div>
            <div class="col-6 col-md-3"><div class="rep-metric-card"><div class="rep-metric-value">R$ ${formatPrice(details.avg_ticket || 0)}</div><div class="rep-metric-label">Ticket Médio</div></div></div>
            <div class="col-6 col-md-3"><div class="rep-metric-card"><div class="rep-metric-value">${details.conversion_rate || 0}%</div><div class="rep-metric-label">Conversão</div></div></div>
        </div>
        <div class="row g-2 mb-3">
            <div class="col-6"><div class="d-flex align-items-center gap-2 small p-2 border rounded"><i class="bi ${growthIcon} ${growthColor}"></i><span>Crescimento vendas: <strong class="${growthColor}">${details.sales_growth || 0}%</strong></span></div></div>
            <div class="col-6"><div class="d-flex align-items-center gap-2 small p-2 border rounded"><i class="bi ${revGrowthIcon} ${revGrowthColor}"></i><span>Crescimento receita: <strong class="${revGrowthColor}">${details.revenue_growth || 0}%</strong></span></div></div>
            <div class="col-6"><div class="d-flex align-items-center gap-2 small p-2 border rounded"><i class="bi bi-eye text-info"></i><span>Visitas 30d: <strong>${formatNumber(details.visits_30d || 0)}</strong></span></div></div>
            <div class="col-6"><div class="d-flex align-items-center gap-2 small p-2 border rounded"><i class="bi bi-box-seam text-muted"></i><span>Ativos: <strong>${details.total_active_items || 0}</strong></span></div></div>
            ${details.revenue_per_item ? `<div class="col-6"><div class="d-flex align-items-center gap-2 small p-2 border rounded"><i class="bi bi-cash-coin text-success"></i><span>Receita/Anúncio: <strong>R$ ${formatPrice(details.revenue_per_item)}</strong></span></div></div>` : ''}
        </div>
        ${(details.stale_count || 0) > 0 ? `<div class="alert alert-warning py-2 small mb-3"><i class="bi bi-exclamation-triangle me-1"></i>${details.stale_count} anúncios parados (${details.stale_percent}% do catálogo) — penalizam o score de vendas</div>` : ''}
        <a href="/dashboard/analytics" class="btn btn-primary btn-sm"><i class="bi bi-bar-chart-line me-1"></i>Ver Analytics</a>
        <a href="/dashboard/seo-killer" class="btn btn-outline-primary btn-sm ms-1"><i class="bi bi-fire me-1"></i>SEO Killer</a>`;
        }

        const modalHtml = `
    <div class="modal fade" id="pillarModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">
                        <i class="bi ${p.icon} me-2 ${getColorClass(p.level)}"></i>
                        ${escapeHtml(p.name)} — Score: ${p.score}/100
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="pillar-score-bar mb-3" style="height:10px;">
                        <div class="pillar-score-fill ${getBgClass(p.level)}" style="width:${p.score}%;height:100%;"></div>
                    </div>
                    ${detailsHtml ? `<div class="mb-3">${detailsHtml}</div><hr>` : ''}
                    <h6 class="fw-bold mb-3">${issues.length > 0 ? 'Problemas Encontrados (' + issues.length + ')' : 'Status'}</h6>
                    ${issuesHtml}
                </div>
            </div>
        </div>
    </div>`;

        // Cleanup previous modal: destroy charts + dispose Bootstrap Modal to avoid memory leaks
        const existingModalEl = document.getElementById('pillarModal');
        if (existingModalEl) {
            const existingBsModal = (typeof bootstrap !== 'undefined' && bootstrap.Modal) ?
                bootstrap.Modal.getInstance(existingModalEl) :
                null;
            if (existingBsModal) existingBsModal.dispose();
            existingModalEl.remove();
        }

        // Destroy chart instances that lived inside the previous modal
        if (salesUnitsChartInstance) {
            salesUnitsChartInstance.destroy();
            salesUnitsChartInstance = null;
        }
        if (salesRevenueChartInstance) {
            salesRevenueChartInstance.destroy();
            salesRevenueChartInstance = null;
        }
        if (ratingsChartInstance) {
            ratingsChartInstance.destroy();
            ratingsChartInstance = null;
        }

        document.body.insertAdjacentHTML('beforeend', modalHtml);

        const pillarModalEl = document.getElementById('pillarModal');
        if (typeof bootstrap !== 'undefined' && bootstrap.Modal) {
            const modal = new bootstrap.Modal(pillarModalEl);
            modal.show();

            // Cleanup when modal is closed by user
            pillarModalEl.addEventListener('hidden.bs.modal', function() {
                if (salesUnitsChartInstance) {
                    salesUnitsChartInstance.destroy();
                    salesUnitsChartInstance = null;
                }
                if (salesRevenueChartInstance) {
                    salesRevenueChartInstance.destroy();
                    salesRevenueChartInstance = null;
                }
                if (ratingsChartInstance) {
                    ratingsChartInstance.destroy();
                    ratingsChartInstance = null;
                }
                const bsModal = bootstrap.Modal.getInstance(this);
                if (bsModal) bsModal.dispose();
                this.remove();
            }, {
                once: true
            });
        }
    }

    // ===================================================================
    // NEW FEATURES: Deltas, Weekly Plan, Category Breakdown, Paused Recovery, History Chart
    // ===================================================================

    function renderScoreDeltas(previousScores, currentOverall, pillars) {
        if (!previousScores || !previousScores.date) return;

        // Hero delta
        const heroCircle = document.getElementById('scoreCircle');
        if (heroCircle && previousScores.overall !== undefined) {
            const diff = Math.round(currentOverall - previousScores.overall);
            if (diff !== 0) {
                const cls = diff > 0 ? 'delta-up' : 'delta-down';
                const sign = diff > 0 ? '+' : '';
                const deltaEl = document.createElement('div');
                deltaEl.className = 'hero-delta ' + cls;
                deltaEl.innerHTML = `<i class="bi bi-arrow-${diff > 0 ? 'up' : 'down'}-short"></i>${sign}${diff}`;
                heroCircle.parentElement.style.position = 'relative';
                heroCircle.parentElement.appendChild(deltaEl);
            }
        }

        // Pillar card deltas
        const pillarKeys = ['reputation', 'seo_quality', 'competitiveness', 'operation', 'sales'];
        const pillarCards = document.querySelectorAll('#pillarCards .pillar-card');

        pillarKeys.forEach((key, idx) => {
            const prev = previousScores[key];
            const current = pillars?.[key]?.score;
            if (prev === undefined || current === undefined) return;

            const diff = Math.round(current - prev);
            if (diff === 0) return;

            const card = pillarCards[idx];
            if (!card) return;

            const scoreEl = card.querySelector('.pillar-score-value');
            if (!scoreEl) return;

            const cls = diff > 0 ? 'delta-up' : 'delta-down';
            const sign = diff > 0 ? '+' : '';
            const badge = document.createElement('span');
            badge.className = 'pillar-delta ' + cls;
            badge.textContent = sign + diff;
            scoreEl.parentElement.appendChild(badge);
        });
    }

    function renderWeeklyPlan(plan) {
        const section = document.getElementById('weeklyPlanSection');
        if (!section) return;

        if (!plan || !plan.actions || plan.actions.length === 0) {
            section.style.display = 'none';
            return;
        }

        section.style.display = 'block';

        // Gain badge
        const gainBadge = document.getElementById('weeklyPlanGain');
        if (gainBadge && plan.estimated_gain > 0) {
            gainBadge.innerHTML = `<i class="bi bi-graph-up-arrow me-1"></i>+${plan.estimated_gain} pts estimados`;
            gainBadge.className = 'badge bg-success bg-opacity-75 text-white';
        }

        // Actions
        const container = document.getElementById('weeklyPlanActions');
        let html = '';

        plan.actions.forEach((action, idx) => {
            const severityClass = action.severity === 'critical' ? 'danger' : (action.severity === 'warning' ? 'warning' : 'info');
            const pillarNames = {
                reputation: 'Reputação',
                seo_quality: 'SEO/Qualidade',
                competitiveness: 'Competitividade',
                operation: 'Operação',
                sales: 'Vendas'
            };
            const pillarLabel = escapeHtml(pillarNames[action.pillar] || action.pillar || '');

            html += `
        <div class="weekly-plan-action">
            <div class="weekly-plan-number">${idx + 1}</div>
            <div style="flex:1">
                <div class="fw-semibold mb-1">${escapeHtml(action.title || action.message || '')}</div>
                <div class="d-flex align-items-center gap-2">
                    <span class="badge bg-${severityClass} bg-opacity-10 text-${severityClass}">
                        ${action.severity === 'critical' ? 'Crítico' : (action.severity === 'warning' ? 'Atenção' : 'Melhoria')}
                    </span>
                    ${pillarLabel ? `<span class="badge bg-light text-dark border">${pillarLabel}</span>` : ''}
                    ${action.affected_count ? `<small class="text-muted">${action.affected_count} itens</small>` : ''}
                </div>
            </div>
            <div class="text-end">
                <span class="text-success fw-bold">+${action.estimated_gain || 0}</span>
                <small class="text-muted d-block">pts</small>
            </div>
        </div>`;
        });

        container.innerHTML = html;

        // Footer
        const footer = document.getElementById('weeklyPlanFooter');
        if (footer && plan.focus_pillar) {
            const focusNames = {
                reputation: 'Reputação',
                seo_quality: 'SEO/Qualidade',
                competitiveness: 'Competitividade',
                operation: 'Operação',
                sales: 'Vendas'
            };
            // Onda 3.1 / F5: o backend (AccountHealthService::generateWeeklyPlan) devolve o
            // campo como `score`, não `current_score` — daí o "undefined" renderizado.
            // Fallback '—' cobre qualquer ausência futura sem voltar a mostrar undefined/NaN.
            const focusScore = (typeof plan.focus_pillar.score === 'number') ? plan.focus_pillar.score : '—';
            const estimatedGain = (typeof plan.estimated_gain === 'number') ? plan.estimated_gain : 0;
            footer.innerHTML = `Foco principal: <strong>${escapeHtml(focusNames[plan.focus_pillar.key] || plan.focus_pillar.key)}</strong> (score atual: ${focusScore}) &mdash; Ganho estimado total: <strong class="text-success">+${estimatedGain} pts</strong>`;
        }
    }

    function renderCategoryBreakdown(categories) {
        const section = document.getElementById('categoryBreakdownSection');
        if (!section) return;

        if (!categories || categories.length === 0) {
            section.style.display = 'none';
            return;
        }

        section.style.display = 'block';
        document.getElementById('categoryCount').textContent = categories.length;

        const container = document.getElementById('categoryBreakdownList');
        const maxScore = Math.max(...categories.map(c => c.issue_score || 0), 1);

        let html = '';
        categories.forEach(cat => {
            const pct = Math.round(((cat.issue_score || 0) / maxScore) * 100);
            const barColor = pct > 66 ? '#ef4444' : (pct > 33 ? '#f59e0b' : '#22c55e');

            html += `
        <div class="category-row">
            <div class="d-flex align-items-center justify-content-between mb-1">
                <div>
                    <span class="fw-semibold">${escapeHtml(cat.name || 'Categoria ' + cat.category_id)}</span>
                    <small class="text-muted ms-2">${cat.total_items || 0} itens</small>
                </div>
                <div class="d-flex align-items-center gap-3">
                    ${cat.zero_sales ? `<small class="text-danger" title="Sem vendas"><i class="bi bi-exclamation-circle"></i> ${cat.zero_sales} sem vendas</small>` : ''}
                    ${cat.low_health ? `<small class="text-warning" title="Saúde baixa"><i class="bi bi-heart-pulse"></i> ${cat.low_health} saúde baixa</small>` : ''}
                    ${cat.no_free_ship ? `<small class="text-info" title="Sem frete grátis"><i class="bi bi-truck"></i> ${cat.no_free_ship} sem frete grátis</small>` : ''}
                </div>
            </div>
            <div class="category-issue-bar">
                <div style="width:${pct}%;height:100%;background:${barColor};border-radius:4px;transition:width 0.6s ease;"></div>
            </div>
        </div>`;
        });

        container.innerHTML = html;
    }

    function renderPausedRecovery(recovery) {
        const section = document.getElementById('pausedRecoverySection');
        if (!section) return;

        if (!recovery || !recovery.total_paused || recovery.total_paused === 0) {
            section.style.display = 'none';
            return;
        }

        section.style.display = 'block';

        // Summary metrics
        document.getElementById('recoveryTotalValue').textContent = 'R$ ' + formatPrice(recovery.recovery_value || 0);
        document.getElementById('recoveryTotalPaused').textContent = recovery.total_paused || 0;
        document.getElementById('recoveryReactivatable').textContent = recovery.reactivatable || 0;
        document.getElementById('recoveryNeedsRestock').textContent = recovery.needs_restock || 0;

        // Items list
        const container = document.getElementById('recoveryItemsList');
        const items = recovery.items || [];

        if (items.length === 0) {
            container.innerHTML = '<p class="text-muted text-center small">Nenhum item pausado com potencial significativo.</p>';
            return;
        }

        let html = '';
        items.forEach(item => {
            const recClass = item.recommendation || 'review';
            const recLabels = {
                reactivate: 'Reativar',
                optimize_then_reactivate: 'Otimizar e reativar',
                restock_needed: 'Repor estoque',
                review: 'Revisar'
            };

            html += `
        <div class="recovery-item">
            <div class="d-flex align-items-center gap-3">
                ${item.thumbnail ? `<img src="${escapeHtml(normalizeExternalUrl(item.thumbnail))}" alt="" style="width:48px;height:48px;object-fit:contain;border-radius:6px;background:#f8f9fa;">` : '<div style="width:48px;height:48px;background:#f1f5f9;border-radius:6px;display:flex;align-items:center;justify-content:center;"><i class="bi bi-image text-muted"></i></div>'}
                <div style="flex:1;min-width:0;">
                    <div class="fw-semibold text-truncate" style="max-width:350px;">${escapeHtml(item.title || 'Item sem título')}</div>
                    <div class="d-flex align-items-center gap-2 mt-1">
                        <span class="recovery-badge ${recClass}">${recLabels[recClass] || recClass}</span>
                        <small class="text-muted">R$ ${formatPrice(item.price || 0)}</small>
                        ${item.available_qty !== undefined ? `<small class="text-muted">Estoque: ${item.available_qty}</small>` : ''}
                    </div>
                </div>
                <div class="text-end">
                    <div class="fw-bold text-success">R$ ${formatPrice(item.monthly_revenue || 0)}<small>/mês</small></div>
                    <small class="text-muted">Score: ${item.reactivate_score || 0}/100</small>
                </div>
                ${item.permalink ? `<a href="${escapeHtml(normalizeExternalUrl(item.permalink))}" target="_blank" rel="noopener" class="btn btn-sm btn-outline-primary ms-2"><i class="bi bi-box-arrow-up-right"></i></a>` : ''}
            </div>
        </div>`;
        });

        container.innerHTML = html;
    }

    function showFullHistoryChart() {
        if (!trendData || !trendData.history || trendData.history.length < 2) return;

        // Remove existing modal
        document.getElementById('historyChartModal')?.remove();

        const history = trendData.history;
        const labels = history.map(h => {
            const d = new Date(h.date || h.created_at);
            return d.toLocaleDateString('pt-BR', {
                day: '2-digit',
                month: '2-digit'
            });
        });

        const pillarConfig = [{
                key: 'overall_score',
                label: 'Geral',
                color: '#6366f1'
            },
            {
                key: 'reputation_score',
                label: 'Reputação',
                color: '#22c55e'
            },
            {
                key: 'seo_quality_score',
                label: 'SEO/Qualidade',
                color: '#3b82f6'
            },
            {
                key: 'competitiveness_score',
                label: 'Competitividade',
                color: '#f59e0b'
            },
            {
                key: 'operation_score',
                label: 'Operação',
                color: '#8b5cf6'
            },
            {
                key: 'sales_score',
                label: 'Vendas',
                color: '#ec4899'
            }
        ];

        const datasets = pillarConfig.map(p => ({
            label: p.label,
            data: history.map(h => parseInt(h[p.key]) || 0),
            borderColor: p.color,
            backgroundColor: p.color + '20',
            borderWidth: p.key === 'overall_score' ? 3 : 1.5,
            pointRadius: p.key === 'overall_score' ? 4 : 2,
            pointHoverRadius: 6,
            tension: 0.3,
            fill: p.key === 'overall_score'
        }));

        const legendHtml = pillarConfig.map(p =>
            `<div class="history-legend-item"><span class="history-legend-dot" style="background:${p.color}"></span>${p.label}</div>`
        ).join('');

        const modalHtml = `
    <div class="modal fade" id="historyChartModal" tabindex="-1" aria-label="Histórico completo de scores">
        <div class="modal-dialog modal-xl modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="bi bi-graph-up me-2"></i>Evolução do Score - Histórico Completo</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fechar"></button>
                </div>
                <div class="modal-body">
                    <div class="history-legend mb-3">${legendHtml}</div>
                    <div class="history-chart-container">
                        <canvas id="fullHistoryCanvas"></canvas>
                    </div>
                    <div class="text-muted text-center small mt-2">${history.length} registros &bull; últimos 90 dias</div>
                </div>
            </div>
        </div>
    </div>`;

        document.body.insertAdjacentHTML('beforeend', modalHtml);

        if (typeof bootstrap === 'undefined' || !bootstrap.Modal) return;
        const modal = new bootstrap.Modal(document.getElementById('historyChartModal'));
        modal.show();

        // Build chart after modal is shown
        document.getElementById('historyChartModal').addEventListener('shown.bs.modal', function() {
            const ctx = document.getElementById('fullHistoryCanvas');
            if (!ctx) return;
            if (historyChartInstance) historyChartInstance.destroy();

            historyChartInstance = new Chart(ctx, {
                type: 'line',
                data: {
                    labels,
                    datasets
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: {
                            display: false
                        },
                        tooltip: {
                            mode: 'index',
                            intersect: false,
                            callbacks: {
                                label: (item) => `${item.dataset.label}: ${item.raw} pts`
                            }
                        }
                    },
                    scales: {
                        x: {
                            grid: {
                                display: false
                            },
                            ticks: {
                                maxRotation: 45,
                                font: {
                                    size: 11
                                }
                            }
                        },
                        y: {
                            min: 0,
                            max: 100,
                            grid: {
                                color: 'rgba(0,0,0,0.06)'
                            },
                            ticks: {
                                stepSize: 20
                            }
                        }
                    },
                    interaction: {
                        mode: 'index',
                        intersect: false
                    }
                }
            });
        }, {
            once: true
        });

        // Cleanup on close
        document.getElementById('historyChartModal').addEventListener('hidden.bs.modal', function() {
            if (historyChartInstance) {
                historyChartInstance.destroy();
                historyChartInstance = null;
            }
            this.remove();
        }, {
            once: true
        });
    }

    // ===================================================================
    // TREND & SPARKLINE
    // ===================================================================

    function renderTrendSparkline(data) {
        const container = document.getElementById('sparklineContainer');
        const history = data.history || [];
        const trend = data.trend;

        if (history.length < 2) {
            container.style.display = 'none';
            return;
        }

        container.style.display = 'inline-flex';
        container.style.cursor = 'pointer';
        container.title = 'Clique para ver histórico completo';
        container.setAttribute('data-action', 'open-history-chart');
        container.setAttribute('data-keyaction', 'open-history-chart');
        container.setAttribute('tabindex', '0');
        container.setAttribute('role', 'button');

        // Sparkline chart
        const ctx = document.getElementById('trendSparkline');
        if (sparklineChartInstance) sparklineChartInstance.destroy();
        if (typeof Chart === 'undefined') return;

        const scores = history.map(h => parseInt(h.overall_score));
        const labels = history.map(h => h.date);

        // Gradient color based on last value
        const lastScore = scores[scores.length - 1];
        const lineColor = lastScore >= 70 ? '#22c55e' : (lastScore >= 50 ? '#f59e0b' : '#ef4444');

        sparklineChartInstance = new Chart(ctx, {
            type: 'line',
            data: {
                labels: labels,
                datasets: [{
                    data: scores,
                    borderColor: lineColor,
                    borderWidth: 2,
                    pointRadius: 0,
                    pointHoverRadius: 3,
                    tension: 0.4,
                    fill: {
                        target: 'origin',
                        above: lineColor + '15',
                    }
                }]
            },
            options: {
                responsive: false,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        display: false
                    },
                    tooltip: {
                        enabled: true,
                        mode: 'index',
                        intersect: false,
                        callbacks: {
                            title: (items) => items[0]?.label || '',
                            label: (item) => 'Score: ' + item.raw
                        }
                    }
                },
                scales: {
                    x: {
                        display: false
                    },
                    y: {
                        display: false,
                        min: Math.max(0, Math.min(...scores) - 10),
                        max: 100
                    }
                },
                interaction: {
                    mode: 'index',
                    intersect: false
                }
            }
        });

        // Trend badge
        if (trend) {
            const badge = document.getElementById('trendBadge');
            const value = document.getElementById('trendValue');
            const change = trend.overall;

            if (change > 0) {
                badge.className = 'trend-badge trend-up';
                badge.querySelector('i').className = 'bi bi-arrow-up-short';
                value.textContent = '+' + change + ' pts';
            } else if (change < 0) {
                badge.className = 'trend-badge trend-down';
                badge.querySelector('i').className = 'bi bi-arrow-down-short';
                value.textContent = change + ' pts';
            } else {
                badge.className = 'trend-badge trend-neutral';
                badge.querySelector('i').className = 'bi bi-dash';
                value.textContent = 'estável';
            }
        }
    }

    function renderPillarTrends(trend) {
        if (!trend) return;

        const pillarMap = {
            'reputation': 'reputation',
            'seo_quality': 'seo',
            'competitiveness': 'competitiveness',
            'operation': 'operation',
            'sales': 'sales'
        };

        document.querySelectorAll('.pillar-card').forEach(card => {
            const pillarKey = card.dataset.pillar;
            if (!pillarKey) return;

            const trendKey = pillarMap[pillarKey];
            if (!trendKey || trend[trendKey] === undefined) return;

            const change = trend[trendKey];
            card.querySelector('.pillar-trend')?.remove();
            if (change === 0) return;

            const scoreEl = card.querySelector('.pillar-score-value');
            if (!scoreEl) return;

            const icon = change > 0 ? 'bi-arrow-up-short' : 'bi-arrow-down-short';
            const color = change > 0 ? 'text-success' : 'text-danger';
            const prefix = change > 0 ? '+' : '';

            scoreEl.insertAdjacentHTML('afterend',
                `<span class="pillar-trend ${color}"><i class="bi ${icon}"></i>${prefix}${change}</span>`
            );
        });
    }

    // ===================================================================
    // EXPORT
    // ===================================================================

    function exportDiagnostic() {
        if (!diagnosticData) {
            Toast.warning('Aguarde o diagnóstico carregar');
            return;
        }

        const d = diagnosticData;
        const pillars = d.pillars || {};
        const actions = d.action_items || [];

        let text = '=== DIAGNÓSTICO DA CONTA ML ===\n';
        text += 'Data: ' + (d.generated_at || new Date().toISOString()) + '\n';
        text += 'Score Geral: ' + d.overall_score + '/100 (' + d.overall_label + ')\n\n';

        text += '--- PILARES ---\n';
        ['reputation', 'seo_quality', 'competitiveness', 'operation', 'sales'].forEach(key => {
            const p = pillars[key];
            if (!p) return;
            const det = p.details || {};
            text += `\n  ${p.name}: ${p.score}/100 (${p.level})\n`;

            // Detalhes específicos por pilar
            if (key === 'reputation') {
                text += `    Nível: ${det.level_id || 'N/A'} | Power Seller: ${det.power_seller || 'Não'}\n`;
                text += `    Reclamações: ${det.claims_rate || 0}% | Cancelamentos: ${det.cancellations_rate || 0}% | Atrasos: ${det.delayed_rate || 0}%\n`;
                text += `    Vendas totais: ${det.total_sales || 0} | Avaliações positivas: ${det.positive_pct || 0}%\n`;
                const repBd = det.score_breakdown || {};
                if (repBd.level) text += `    Score: Nível ${repBd.level.score}/${repBd.level.max} | ML ${repBd.power_seller?.score || 0}/${repBd.power_seller?.max || 20} | Reclam ${repBd.claims?.score || 0}/${repBd.claims?.max || 15} | Cancel ${repBd.cancellations?.score || 0}/${repBd.cancellations?.max || 15} | Atrasos ${repBd.delays?.score || 0}/${repBd.delays?.max || 10}\n`;
            } else if (key === 'seo_quality') {
                text += `    Ativos: ${det.total_active || 0} | Analisados: ${det.analyzed || 0} | Score < 70: ${det.items_below_70 || 0}\n`;
                text += `    Títulos fracos: ${det.title_issues || 0} | Poucas fotos: ${det.image_issues || 0} | Atributos: ${det.attribute_issues || 0}\n`;
                text += `    Descrições fracas: ${det.description_issues || 0} | Sem frete grátis: ${det.shipping_issues || 0}\n`;
                text += `    No catálogo ML: ${det.catalog_items || 0} (${det.catalog_pct || 0}%) | Fora: ${det.non_catalog_items || 0}\n`;
                text += `    Ficha técnica incompleta (ML): ${det.incomplete_specs || 0}\n`;
                text += `    Fonte do score: ${det.score_source || 'local'}\n`;
                if (det.ml_quality) {
                    const mlq = det.ml_quality;
                    text += `    Qualidade ML oficial: Excelente=${mlq.excellent || 0} Bom=${mlq.good || 0} Regular=${mlq.regular || 0} Ruim=${mlq.poor || 0}\n`;
                }
            } else if (key === 'competitiveness') {
                text += `    Analisados: ${det.analyzed || 0} | Competitivos: ${det.good_items || 0}\n`;
                text += `    Sem frete grátis: ${det.no_free_shipping || 0} | Não Premium: ${det.no_gold_pro || 0} | Saúde baixa: ${det.low_health || 0}\n`;
                text += `    No catálogo: ${det.catalog_items || 0} (${det.catalog_pct || 0}%) | Com tração: ${det.dragged_visits_items || 0} | Best seller: ${det.best_seller_candidates || 0}\n`;
            } else if (key === 'operation') {
                text += `    Pedidos 30d: ${det.orders_30d || 0} | Cancelados: ${det.cancelled_30d || 0} (${det.cancel_rate || 0}%)\n`;
                text += `    Taxa atrasos: ${det.delay_rate || 0}% | Perguntas s/ resp: ${det.unanswered_questions || 0} | Fulfillment: ${det.fulfillment_enabled ? 'Sim' : 'Não'}\n`;
                if (det.open_claims !== undefined) text += `    Mediações abertas: ${det.open_claims} | Total reclamações: ${det.total_claims || 0} | Contra vendedor: ${det.claims_against_seller || 0}\n`;
                if (det.avg_response_time_min !== undefined) text += `    Tempo médio resposta: ${(det.avg_response_time_min / 60).toFixed(1)}h | Respondidas: ${det.questions_answered || 0}\n`;
                if (det.under_review_items !== undefined) text += `    Em revisão ML: ${det.under_review_items} | Inativos: ${det.inactive_items || 0}\n`;
                if (det.top_claim_reason) text += `    Principal motivo reclamação: ${det.top_claim_reason}\n`;
                const bd = det.score_breakdown || {};
                if (bd.delay) text += `    Score: Envio ${bd.delay.score}/${bd.delay.max} | Atendimento ${bd.questions?.score || 0}/${bd.questions?.max || 15} | Logística ${bd.shipping?.score || 0}/${bd.shipping?.max || 20} | Cancelamento ${bd.orders?.score || 0}/${bd.orders?.max || 15} | Mediações ${bd.claims?.score || 0}/${bd.claims?.max || 15} | Compliance ${bd.compliance?.score || 0}/${bd.compliance?.max || 15}\n`;
            } else if (key === 'sales') {
                text += `    Vendas 30d: ${det.sales_30d || 0} | Receita: R$ ${formatPrice(det.revenue_30d || 0)}\n`;
                text += `    Crescimento vendas: ${det.sales_growth || 0}% | Crescimento receita: ${det.revenue_growth || 0}%\n`;
                text += `    Visitas: ${det.visits_30d || 0} | Conversão: ${det.conversion_rate || 0}% | Ticket médio: R$ ${formatPrice(det.avg_ticket || 0)}\n`;
                if (det.revenue_per_item) text += `    Receita/Anúncio ativo: R$ ${formatPrice(det.revenue_per_item)}\n`;
            }

            // Issues do pilar
            const pillarIssues = p.issues || [];
            if (pillarIssues.length > 0) {
                text += `    Problemas (${pillarIssues.length}):\n`;
                pillarIssues.forEach(iss => {
                    text += `      - [${iss.severity.toUpperCase()}] ${iss.message}\n`;
                });
            }
        });

        if (actions.length > 0) {
            text += '\n--- AÇÕES RECOMENDADAS (' + actions.length + ') ---\n';
            actions.forEach((a, i) => {
                text += `  ${i + 1}. [${a.severity.toUpperCase()}] ${a.message}\n`;
                text += `     Impacto: ${a.impact}\n`;
                text += `     Ação: ${a.action}\n\n`;
            });
        }

        if (d.summary) {
            text += '--- RESUMO ---\n';
            text += `  Problemas críticos: ${d.summary.critical_count}\n`;
            text += `  Alertas: ${d.summary.warning_count}\n`;
            text += `  Total de ações: ${d.summary.total_actions}\n`;
            if (d.summary.worst_pillar) text += `  Pior pilar: ${d.summary.worst_pillar} (${d.summary.worst_pillar_score})\n`;
            if (d.summary.best_pillar) text += `  Melhor pilar: ${d.summary.best_pillar} (${d.summary.best_pillar_score})\n`;
            if (d.summary.potential_gain > 0) text += `  Ganho potencial: +${d.summary.potential_gain} pontos (se o pior pilar subir para 70)\n`;
            text += `  Recomendação: ${d.summary.recommendation}\n`;
        }

        // Stale listings
        const stale = d.stale_listings;
        if (stale && stale.summary && stale.summary.total_stale > 0) {
            text += '\n--- ANÚNCIOS PARADOS ---\n';
            text += `  Total parados: ${stale.summary.total_stale} (${stale.summary.stale_percent}% do catálogo)\n`;
            text += `  Críticos (90+ dias): ${stale.summary.critical_count}\n`;
            text += `  Alerta (60-90 dias): ${stale.summary.warning_count}\n`;
            text += `  Valor parado: R$ ${formatPrice(stale.summary.frozen_value || 0)}\n`;
            text += `  Impacto: ${stale.summary.impact_level}\n`;
            if (stale.impact && stale.impact.recommendation) {
                text += `  Recomendação: ${stale.impact.recommendation}\n`;
            }
            const staleCauses = stale.summary.cause_counts || {};
            if (Object.keys(staleCauses).length > 0) {
                const causeNames = {
                    tipo_anuncio: 'Tipo anúncio fraco',
                    sem_frete_gratis: 'Sem frete grátis',
                    saude_baixa: 'Saúde baixa',
                    fora_catalogo: 'Fora do catálogo'
                };
                text += '  Causas prováveis:\n';
                Object.entries(staleCauses).forEach(([k, v]) => {
                    text += `    - ${causeNames[k] || k}: ${v} anúncios\n`;
                });
            }
            text += '\n  Lista dos anúncios:\n';
            (stale.items || []).forEach((item, i) => {
                text += `    ${i + 1}. [${item.severity.toUpperCase()}] ${item.title}\n`;
                text += `       ID: ${item.id} | ${item.days_active} dias | R$ ${formatPrice(item.price)} | Estoque: ${item.available_qty}`;
                if (item.causes && item.causes.length > 0) text += ` | Causas: ${item.causes.join(', ')}`;
                text += '\n';
            });
        }

        // Cross-pillar insights
        const crossInsights = d.cross_insights || [];
        if (crossInsights.length > 0) {
            text += '\n--- INSIGHTS CRUZADOS ---\n';
            crossInsights.forEach((insight, i) => {
                text += `  ${i + 1}. ${insight.title}\n`;
                text += `     ${insight.message}\n`;
                text += `     Pilares: ${(insight.pillars || []).join(', ')}\n\n`;
            });
        }

        // Download
        const blob = new Blob([text], {
            type: 'text/plain;charset=utf-8'
        });
        const url = URL.createObjectURL(blob);
        const a = document.createElement('a');
        a.href = url;
        a.download = 'diagnostico-conta-ml-' + new Date().toISOString().slice(0, 10) + '.txt';
        a.click();
        URL.revokeObjectURL(url);
        Toast.success('Relatório exportado!');
    }

    // ===================================================================
    // HELPERS
    // ===================================================================

    function animateNumber(el, start, end, duration) {
        const startTime = performance.now();

        function update(currentTime) {
            const elapsed = currentTime - startTime;
            const progress = Math.min(elapsed / duration, 1);
            const eased = 1 - Math.pow(1 - progress, 3); // easeOutCubic
            el.textContent = Math.round(start + (end - start) * eased);
            if (progress < 1) requestAnimationFrame(update);
        }
        requestAnimationFrame(update);
    }

    function getColorClass(level) {
        return {
            'critical': 'text-danger',
            'warning': 'text-warning',
            'good': 'text-success',
            'great': 'text-info'
        } [level] || 'text-muted';
    }

    function getColorBg(level) {
        return {
            'critical': 'bg-danger bg-opacity-10',
            'warning': 'bg-warning bg-opacity-10',
            'good': 'bg-success bg-opacity-10',
            'great': 'bg-info bg-opacity-10'
        } [level] || 'bg-light';
    }

    function getBgClass(level) {
        return {
            'critical': 'bg-danger',
            'warning': 'bg-warning',
            'good': 'bg-success',
            'great': 'bg-info'
        } [level] || 'bg-secondary';
    }

    function getSeverityLabel(severity) {
        return {
            'critical': 'CRÍTICO',
            'warning': 'ALERTA',
            'info': 'DICA'
        } [severity] || severity;
    }

    function getQuickActionLink(actionType) {
        const links = {
            'poor_titles': {
                url: '/seo',
                label: 'Otimizar SEO',
                icon: 'bi-stars'
            },
            'few_images': {
                url: '/dashboard/items',
                label: 'Ver Anúncios',
                icon: 'bi-image'
            },
            'no_free_shipping': {
                url: '/dashboard/items',
                label: 'Ver Anúncios',
                icon: 'bi-truck'
            },
            'missing_attributes': {
                url: '/dashboard/seo/ficha-tecnica',
                label: 'Ficha Técnica',
                icon: 'bi-card-checklist'
            },
            'weak_descriptions': {
                url: '/seo',
                label: 'Otimizar',
                icon: 'bi-text-paragraph'
            },
            'overpriced': {
                url: '/dashboard/items',
                label: 'Ajustar Preços',
                icon: 'bi-tag'
            },
            'unanswered_questions': {
                url: '/dashboard/questions',
                label: 'Responder',
                icon: 'bi-chat-dots'
            },
            'no_fulfillment': {
                url: 'https://www.mercadolivre.com.br/gz/shipping/preferences',
                label: 'Config. Frete',
                icon: 'bi-truck'
            },
            'sales_declining': {
                url: '/dashboard/analytics',
                label: 'Analytics',
                icon: 'bi-bar-chart-line'
            },
            'low_conversion': {
                url: '/seo',
                label: 'Otimizar SEO',
                icon: 'bi-stars'
            },
            'low_visibility': {
                url: '/seo',
                label: 'Otimizar SEO',
                icon: 'bi-stars'
            },
            'no_active_items': {
                url: '/dashboard/items',
                label: 'Criar Anúncio',
                icon: 'bi-plus-circle'
            },
            'high_claims': {
                url: '/dashboard/orders',
                label: 'Ver Pedidos',
                icon: 'bi-exclamation-triangle'
            },
            'high_cancellations': {
                url: '/dashboard/orders',
                label: 'Ver Pedidos',
                icon: 'bi-x-circle'
            },
            'delayed_shipping': {
                url: '/dashboard/orders',
                label: 'Ver Pedidos',
                icon: 'bi-clock-history'
            },
            'low_positive_ratings': {
                url: '/dashboard/orders',
                label: 'Gerenciar',
                icon: 'bi-star-half'
            },
            'stale_listings': {
                url: '/dashboard/items',
                label: 'Ver Anúncios',
                icon: 'bi-archive'
            },
            'low_health': {
                url: '/dashboard/items',
                label: 'Ver Anúncios',
                icon: 'bi-heart-pulse'
            },
            'not_gold_pro': {
                url: '/dashboard/items',
                label: 'Upgrade Tipo',
                icon: 'bi-star'
            },
            'not_in_catalog': {
                url: '/dashboard/items',
                label: 'Ver Anúncios',
                icon: 'bi-book'
            },
            'incomplete_specs': {
                url: '/dashboard/seo/ficha-tecnica',
                label: 'Ficha Técnica',
                icon: 'bi-list-check'
            },
            'ml_quality_low': {
                url: '/seo',
                label: 'Otimizar',
                icon: 'bi-patch-check'
            },
            'zero_sales': {
                url: '/dashboard/items',
                label: 'Ver Anúncios',
                icon: 'bi-graph-down'
            },
        };
        return links[actionType] || null;
    }

    function formatNumber(value) {
        return parseInt(value || 0).toLocaleString('pt-BR');
    }

    function formatPrice(value) {
        return parseFloat(value || 0).toLocaleString('pt-BR', {
            minimumFractionDigits: 2,
            maximumFractionDigits: 2
        });
    }

    function formatDateTime(str) {
        if (!str) return '';
        const d = new Date(str);
        return d.toLocaleDateString('pt-BR') + ' ' + d.toLocaleTimeString('pt-BR', {
            hour: '2-digit',
            minute: '2-digit'
        });
    }

    function escapeHtml(str) {
        const div = document.createElement('div');
        div.textContent = str || '';
        return div.innerHTML;
    }

    function showAccountDisconnected(nickname, accountId) {
        const name = nickname || ('Conta #' + accountId);
        document.getElementById('scoreValue').textContent = '!';
        document.getElementById('scoreLabel').textContent = 'Desconectada';
        document.getElementById('mainRecommendation').textContent =
            'A conta ' + name + ' precisa ser reconectada ao Mercado Livre para exibir o diagnóstico.';

        document.getElementById('pillarCards').innerHTML = `
        <div class="col-12">
            <div class="alert alert-danger border-0 shadow-sm" style="border-radius: 1rem;">
                <div class="d-flex align-items-start gap-3">
                    <div class="fs-1">
                        <i class="bi bi-plug text-danger"></i>
                    </div>
                    <div class="flex-grow-1">
                        <h5 class="alert-heading mb-2">
                            <strong>${escapeHtml(name)}</strong> está desconectada
                        </h5>
                        <p class="mb-3">
                            Os tokens de acesso desta conta expiraram ou foram revogados.
                            Para visualizar o diagnóstico completo com dados reais, reconecte a conta ao Mercado Livre.
                        </p>
                        <div class="d-flex gap-2 flex-wrap">
                            <a href="/dashboard/accounts" class="btn btn-danger">
                                <i class="bi bi-arrow-repeat me-1"></i>Reconectar Conta
                            </a>
                            <a href="/dashboard/accounts" class="btn btn-outline-secondary">
                                <i class="bi bi-arrow-left-right me-1"></i>Trocar de Conta
                            </a>
                        </div>
                    </div>
                </div>
            </div>
        </div>`;
    }

    function showError(message) {
        document.getElementById('scoreValue').textContent = '!';
        document.getElementById('scoreLabel').textContent = 'Erro';
        document.getElementById('mainRecommendation').textContent = message;

        const hasCache = !!loadFromCache();
        const cacheHint = hasCache ?
            '<p class="mb-0 mt-2 small opacity-75"><i class="bi bi-info-circle me-1"></i>Dados em cache estão sendo exibidos nas seções abaixo.</p>' :
            '';

        document.getElementById('pillarCards').innerHTML = `
        <div class="col-12">
            <div class="alert alert-warning">
                <i class="bi bi-exclamation-triangle me-2"></i>
                ${escapeHtml(message)}
                <button class="btn btn-sm btn-warning ms-2" data-action="retry-load-diagnostic">
                    <i class="bi bi-arrow-clockwise me-1"></i>Tentar novamente
                </button>
                ${cacheHint}
            </div>
        </div>`;
    }

    // ========================================
    // EVENT DELEGATION FOR CSP COMPLIANCE
    // ========================================
    document.addEventListener('click', function(e) {
        const target = e.target.closest('[data-action]');
        if (!target) return;

        const action = target.dataset.action;
        if (!action) return;

        // Don't prevent default for links without special actions
        if (![
                'refresh-diagnostic',
                'toggle-export-menu',
                'export-diagnostic-txt',
                'export-actions-csv',
                'export-json',
                'share-diagnostic',
                'print-diagnostic',
                'set-score-goal',
                'filter-actions',
                'toggle-stale-items',
                'show-pillar-detail',
                'toggle-action-complete',
                'retry-load-diagnostic',
                'open-history-chart',
                'show-reconnection-prompt',
            ].includes(action)) {
            return;
        }

        e.preventDefault();

        switch (action) {
            case 'refresh-diagnostic':
                refreshDiagnostic();
                break;
            case 'toggle-export-menu':
                toggleExportMenu();
                break;
            case 'export-diagnostic-txt':
                exportDiagnostic();
                closeExportMenu();
                break;
            case 'export-actions-csv':
                exportActionsCsv();
                closeExportMenu();
                break;
            case 'export-json':
                exportJson();
                closeExportMenu();
                break;
            case 'share-diagnostic':
                shareDiagnostic();
                break;
            case 'print-diagnostic':
                window.print();
                break;
            case 'set-score-goal':
                setScoreGoal();
                break;
            case 'filter-actions':
                filterActions(target.dataset.filter, target);
                break;
            case 'toggle-stale-items':
                toggleStaleItems();
                break;
            case 'show-pillar-detail':
                showPillarDetail(target.dataset.pillar);
                break;
            case 'toggle-action-complete':
                toggleActionComplete(target.dataset.actionKey, target);
                break;
            case 'retry-load-diagnostic':
                retryCount = 0;
                loadDiagnostic();
                break;
            case 'open-history-chart':
                showFullHistoryChart();
                break;
            case 'show-reconnection-prompt':
                showReconnectionPrompt(dataQualityConnectionErrors);
                break;
        }
    });

    // Handle keyboard events for accessibility
    document.addEventListener('keydown', function(e) {
        const target = e.target.closest('[data-keyaction]');
        if (!target || e.key !== 'Enter') return;
        e.preventDefault();
        if (target.dataset.keyaction === 'show-pillar-detail') {
            showPillarDetail(target.dataset.pillar);
            return;
        }

        if (target.dataset.keyaction === 'open-history-chart') {
            showFullHistoryChart();
            return;
        }

        if (target.dataset.keyaction === 'show-reconnection-prompt') {
            showReconnectionPrompt(dataQualityConnectionErrors);
        }
    });
</script>
