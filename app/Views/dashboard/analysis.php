<?php

declare(strict_types=1);

/**
 * Deep Research Intelligence
 *
 * @uses layouts/modern/app.php (via ViewController)
 */

$pageTitle = 'Deep Research Intelligence';
$title = 'Deep Research Intelligence';
$subtitle = 'Análise profunda de mercado e concorrência';
$breadcrumbs = [
    ['label' => 'Dashboard', 'url' => '/dashboard'],
    ['label' => 'Análise']
];
$activePage = 'analysis';

// Account Selector
$requireAccountSelection = true;
$showAccountBanner = true;
include __DIR__ . '/../components/account-selector.php';
?>

<!-- Page Header -->
<?php include __DIR__ . '/../layouts/modern/partials/page-header.php'; ?>

<div class="container-fluid px-0 px-md-4 py-0">
    <!-- CSS Dependencies specific to this page -->
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/select2-bootstrap-5-theme@1.3.0/dist/select2-bootstrap-5-theme.min.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

    <style>
        :root {
            --primary: #FFE600;
            --primary-dark: #E6CF00;
            --secondary: #3483FA;
            --dark: #333333;
            --light-bg: #F5F5F5;
            --success: #00A650;
            --warning: #FF7733;
            --danger: #F23D4F;
        }

        /* Scoped styles mainly */
        .research-header {
            background: linear-gradient(135deg, var(--primary) 0%, var(--primary-dark) 100%);
            padding: 2rem 0;
            margin-bottom: 2rem;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.3);
        }

        .research-title {
            color: var(--dark);
            font-weight: 800;
            font-size: 2.5rem;
            text-shadow: none;
        }

        .research-subtitle {
            color: rgba(0, 0, 0, 0.7);
            font-size: 1.1rem;
        }

        .glass-card {
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(10px);
            border-radius: 16px;
            box-shadow: 0 8px 32px rgba(0, 0, 0, 0.2);
            padding: 1.5rem;
            margin-bottom: 1.5rem;
            border: 1px solid rgba(255, 255, 255, 0.2);
        }

        .dark-card {
            background: rgba(0, 0, 0, 0.4);
            backdrop-filter: blur(10px);
            border-radius: 16px;
            box-shadow: 0 8px 32px rgba(0, 0, 0, 0.3);
            padding: 1.5rem;
            margin-bottom: 1.5rem;
            border: 1px solid rgba(255, 255, 255, 0.1);
            color: #fff;
        }

        .stat-card {
            background: linear-gradient(135deg, rgba(255, 255, 255, 0.1) 0%, rgba(255, 255, 255, 0.05) 100%);
            border-radius: 12px;
            padding: 1.5rem;
            text-align: center;
            border: 1px solid rgba(255, 255, 255, 0.1);
            transition: transform 0.3s ease;
        }

        .stat-card:hover {
            transform: translateY(-5px);
        }

        .stat-value {
            font-size: 2.5rem;
            font-weight: 700;
            color: var(--primary);
            line-height: 1;
        }

        .stat-label {
            font-size: 0.85rem;
            color: rgba(255, 255, 255, 0.7);
            text-transform: uppercase;
            letter-spacing: 1px;
            margin-top: 0.5rem;
        }

        .search-form {
            background: var(--dark);
            border-radius: 16px;
            padding: 2rem;
            margin-bottom: 2rem;
        }

        .search-form label {
            color: #fff;
            font-weight: 600;
            margin-bottom: 0.5rem;
        }

        .search-form .form-control,
        .search-form .form-select {
            border-radius: 8px;
            padding: 0.75rem 1rem;
            border: 2px solid rgba(255, 255, 255, 0.1);
            background: rgba(255, 255, 255, 0.1);
            color: #fff;
        }

        .search-form .form-control::placeholder {
            color: rgba(255, 255, 255, 0.5);
        }

        .search-form .form-control:focus,
        .search-form .form-select:focus {
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(255, 230, 0, 0.2);
            background: rgba(255, 255, 255, 0.15);
            color: #fff;
        }

        /* Custom Select2 Dark Theme */
        .select2-container--bootstrap-5 .select2-selection {
            background: rgba(255, 255, 255, 0.1) !important;
            border: 2px solid rgba(255, 255, 255, 0.1) !important;
            border-radius: 8px !important;
            min-height: 48px !important;
            color: #fff !important;
        }

        .select2-container--bootstrap-5 .select2-selection--single .select2-selection__rendered {
            color: #fff !important;
            line-height: 44px !important;
            padding-left: 1rem !important;
        }

        .select2-container--bootstrap-5 .select2-selection--single .select2-selection__placeholder {
            color: rgba(255, 255, 255, 0.5) !important;
        }

        .select2-container--bootstrap-5 .select2-selection--single .select2-selection__arrow {
            height: 46px !important;
        }

        .select2-container--bootstrap-5 .select2-selection--single .select2-selection__arrow b {
            border-color: #fff transparent transparent transparent !important;
        }

        .select2-container--bootstrap-5.select2-container--open .select2-selection {
            border-color: var(--primary) !important;
            box-shadow: 0 0 0 3px rgba(255, 230, 0, 0.2) !important;
        }

        .select2-container--bootstrap-5 .select2-dropdown {
            background: #2a2a3e !important;
            border: 1px solid rgba(255, 255, 255, 0.2) !important;
            border-radius: 8px !important;
            box-shadow: 0 8px 32px rgba(0, 0, 0, 0.4) !important;
        }

        .select2-container--bootstrap-5 .select2-search--dropdown .select2-search__field {
            background: rgba(255, 255, 255, 0.1) !important;
            border: 1px solid rgba(255, 255, 255, 0.2) !important;
            color: #fff !important;
            border-radius: 6px !important;
        }

        .select2-container--bootstrap-5 .select2-results__option {
            color: #fff !important;
            padding: 10px 15px !important;
        }

        .select2-container--bootstrap-5 .select2-results__option--highlighted {
            background: var(--primary) !important;
            color: var(--dark) !important;
        }

        .select2-container--bootstrap-5 .select2-results__option--selected {
            background: rgba(255, 230, 0, 0.3) !important;
        }

        .select2-container--bootstrap-5 .select2-results__option .category-option {
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .select2-container--bootstrap-5 .select2-results__option .category-option .category-path {
            font-size: 0.75rem;
            color: rgba(255, 255, 255, 0.5);
        }

        /* Popular Categories Tags */
        .popular-categories {
            display: flex;
            flex-wrap: wrap;
            gap: 0.5rem;
            margin-top: 1rem;
        }

        .category-tag {
            background: rgba(52, 131, 250, 0.2);
            border: 1px solid rgba(52, 131, 250, 0.4);
            color: #fff;
            padding: 0.4rem 0.8rem;
            border-radius: 20px;
            font-size: 0.8rem;
            cursor: pointer;
            transition: all 0.2s ease;
            display: inline-flex;
            align-items: center;
            gap: 0.4rem;
        }

        .category-tag:hover {
            background: var(--primary);
            color: var(--dark);
            border-color: var(--primary);
        }

        .category-tag i {
            font-size: 0.7rem;
        }

        /* Recent Searches */
        .recent-searches {
            margin-top: 1rem;
        }

        .recent-search-item {
            background: rgba(255, 255, 255, 0.05);
            border: 1px solid rgba(255, 255, 255, 0.1);
            border-radius: 8px;
            padding: 0.6rem 1rem;
            margin-bottom: 0.5rem;
            display: flex;
            align-items: center;
            justify-content: space-between;
            cursor: pointer;
            transition: all 0.2s ease;
        }

        .recent-search-item:hover {
            background: rgba(255, 255, 255, 0.1);
            border-color: var(--primary);
        }

        .recent-search-item .search-info {
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }

        .recent-search-item .search-info i {
            color: var(--primary);
        }

        .recent-search-item .search-details {
            font-size: 0.85rem;
            color: rgba(255, 255, 255, 0.6);
        }

        .recent-search-item .remove-btn {
            color: rgba(255, 255, 255, 0.4);
            cursor: pointer;
            padding: 0.25rem;
        }

        .recent-search-item .remove-btn:hover {
            color: var(--danger);
        }

        /* Brand badges */
        .brand-badge {
            background: rgba(0, 166, 80, 0.2);
            border: 1px solid rgba(0, 166, 80, 0.4);
            color: var(--success);
            padding: 0.3rem 0.6rem;
            border-radius: 4px;
            font-size: 0.75rem;
            font-weight: 600;
        }

        /* Advanced Filters Panel */
        .filters-panel {
            background: rgba(0, 0, 0, 0.3);
            border-radius: 12px;
            padding: 1.25rem;
            margin-top: 1rem;
            border: 1px solid rgba(255, 255, 255, 0.1);
        }

        .filters-toggle {
            color: var(--primary);
            cursor: pointer;
            font-size: 0.9rem;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            transition: all 0.2s ease;
        }

        .filters-toggle:hover {
            color: #fff;
        }

        .filters-toggle i {
            transition: transform 0.3s ease;
        }

        .filters-toggle.active i {
            transform: rotate(180deg);
        }

        .filter-group {
            margin-bottom: 1rem;
        }

        .filter-group label {
            color: rgba(255, 255, 255, 0.8);
            font-size: 0.85rem;
            margin-bottom: 0.5rem;
            display: block;
        }

        .filter-input {
            background: rgba(255, 255, 255, 0.1);
            border: 1px solid rgba(255, 255, 255, 0.15);
            border-radius: 6px;
            color: #fff;
            padding: 0.5rem 0.75rem;
            font-size: 0.9rem;
            width: 100%;
        }

        .filter-input:focus {
            border-color: var(--primary);
            outline: none;
            box-shadow: 0 0 0 2px rgba(255, 230, 0, 0.2);
        }

        .filter-input::placeholder {
            color: rgba(255, 255, 255, 0.4);
        }

        .filter-chips {
            display: flex;
            flex-wrap: wrap;
            gap: 0.5rem;
        }

        .filter-chip {
            background: rgba(255, 255, 255, 0.1);
            border: 1px solid rgba(255, 255, 255, 0.2);
            border-radius: 20px;
            padding: 0.4rem 0.8rem;
            font-size: 0.8rem;
            color: rgba(255, 255, 255, 0.8);
            cursor: pointer;
            transition: all 0.2s ease;
        }

        .filter-chip:hover,
        .filter-chip.active {
            background: var(--primary);
            color: var(--dark);
            border-color: var(--primary);
        }

        .filter-chip i {
            margin-right: 0.4rem;
        }

        .price-range-inputs {
            display: flex;
            gap: 0.5rem;
            align-items: center;
        }

        .price-range-inputs input {
            flex: 1;
        }

        .price-range-inputs span {
            color: rgba(255, 255, 255, 0.5);
        }

        /* Quick Stats Bar */
        .quick-stats-bar {
            background: rgba(255, 230, 0, 0.1);
            border: 1px solid rgba(255, 230, 0, 0.2);
            border-radius: 8px;
            padding: 0.75rem 1rem;
            margin-bottom: 1rem;
            display: flex;
            align-items: center;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: 1rem;
        }

        .quick-stat {
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .quick-stat i {
            color: var(--primary);
        }

        .quick-stat-value {
            font-weight: 700;
            color: #fff;
        }

        .quick-stat-label {
            font-size: 0.8rem;
            color: rgba(255, 255, 255, 0.6);
        }

        .btn-research {
            background: linear-gradient(135deg, var(--primary) 0%, var(--primary-dark) 100%);
            color: var(--dark);
            font-weight: 700;
            padding: 0.75rem 2rem;
            border-radius: 8px;
            border: none;
            font-size: 1.1rem;
            transition: all 0.3s ease;
        }

        .btn-research:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 15px rgba(255, 230, 0, 0.4);
            color: var(--dark);
        }


        .btn-research:disabled {
            opacity: 0.6;
            transform: none;
        }

        .opportunity-card {
            background: linear-gradient(135deg, rgba(0, 166, 80, 0.2) 0%, rgba(0, 166, 80, 0.1) 100%);
            border-left: 4px solid var(--success);
            border-radius: 8px;
            padding: 1rem 1.25rem;
            margin-bottom: 1rem;
        }

        .opportunity-card.high {
            background: linear-gradient(135deg, rgba(242, 61, 79, 0.2) 0%, rgba(242, 61, 79, 0.1) 100%);
            border-color: var(--danger);
        }

        .opportunity-card.medium {
            background: linear-gradient(135deg, rgba(255, 119, 51, 0.2) 0%, rgba(255, 119, 51, 0.1) 100%);
            border-color: var(--warning);
        }

        .priority-badge {
            font-size: 0.7rem;
            padding: 0.25rem 0.5rem;
            border-radius: 4px;
            text-transform: uppercase;
            font-weight: 700;
        }

        .priority-high {
            background: var(--danger);
            color: #fff;
        }

        .priority-medium {
            background: var(--warning);
            color: #fff;
        }

        .priority-low {
            background: var(--success);
            color: #fff;
        }

        .seller-row {
            background: rgba(255, 255, 255, 0.05);
            border-radius: 8px;
            padding: 1rem;
            margin-bottom: 0.75rem;
            transition: all 0.3s ease;
        }

        .seller-row:hover {
            background: rgba(255, 255, 255, 0.1);
        }

        .seller-rank {
            width: 40px;
            height: 40px;
            background: var(--primary);
            color: var(--dark);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 700;
            font-size: 1.1rem;
        }

        .seller-rank.gold {
            background: linear-gradient(135deg, #FFD700, #FFA500);
        }

        .seller-rank.silver {
            background: linear-gradient(135deg, #C0C0C0, #A0A0A0);
        }

        .seller-rank.bronze {
            background: linear-gradient(135deg, #CD7F32, #B87333);
        }

        .progress-ring {
            width: 80px;
            height: 80px;
        }

        .chart-container {
            position: relative;
            height: 300px;
        }

        .loading-overlay {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(0, 0, 0, 0.8);
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            z-index: 9999;
        }

        .loading-spinner {
            width: 80px;
            height: 80px;
            border: 4px solid rgba(255, 230, 0, 0.2);
            border-top-color: var(--primary);
            border-radius: 50%;
            animation: spin 1s linear infinite;
        }

        @keyframes spin {
            to {
                transform: rotate(360deg);
            }
        }

        .loading-text {
            color: #fff;
            margin-top: 1.5rem;
            font-size: 1.2rem;
        }

        .loading-progress {
            width: 300px;
            height: 6px;
            background: rgba(255, 255, 255, 0.1);
            border-radius: 3px;
            margin-top: 1rem;
            overflow: hidden;
        }

        .loading-progress-bar {
            height: 100%;
            background: var(--primary);
            border-radius: 3px;
            width: 0%;
            transition: width 0.3s ease;
        }

        .loading-stats {
            color: rgba(255, 255, 255, 0.6);
            font-size: 0.85rem;
            margin-top: 0.75rem;
        }

        /* Progress Steps */
        .progress-steps {
            display: flex;
            gap: 30px;
            margin: 2rem 0 1rem;
        }

        .progress-step {
            display: flex;
            flex-direction: column;
            align-items: center;
            opacity: 0.4;
            transition: all 0.3s ease;
        }

        .progress-step.active {
            opacity: 1;
        }

        .progress-step.completed {
            opacity: 1;
        }

        .progress-step.completed .step-icon {
            background: var(--success);
            border-color: var(--success);
        }

        .step-icon {
            width: 50px;
            height: 50px;
            border-radius: 50%;
            background: rgba(255, 255, 255, 0.1);
            border: 2px solid var(--primary);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.2rem;
            color: var(--primary);
            margin-bottom: 8px;
        }

        .progress-step.active .step-icon {
            background: var(--primary);
            color: var(--dark);
            animation: pulse 1.5s infinite;
        }

        @keyframes pulse {

            0%,
            100% {
                transform: scale(1);
            }

            50% {
                transform: scale(1.1);
            }
        }

        .step-label {
            font-size: 0.75rem;
            text-transform: uppercase;
            letter-spacing: 1px;
            color: #fff;
        }

        /* ML Status Badge */
        .ml-status {
            display: flex;
            align-items: center;
            gap: 8px;
            padding: 8px 16px;
            background: rgba(0, 0, 0, 0.3);
            border-radius: 20px;
            font-size: 0.85rem;
            color: #fff;
        }

        .ml-status.connected {
            background: rgba(0, 166, 80, 0.2);
            color: var(--success);
        }

        .ml-status.disconnected {
            background: rgba(242, 61, 79, 0.2);
            color: var(--danger);
            cursor: pointer;
        }

        .ml-status.disconnected:hover {
            background: rgba(242, 61, 79, 0.3);
        }

        /* Favorite Button */
        .favorite-btn {
            background: transparent;
            border: none;
            color: rgba(255, 255, 255, 0.5);
            font-size: 1.2rem;
            cursor: pointer;
            transition: all 0.2s;
            padding: 5px;
        }

        .favorite-btn:hover,
        .favorite-btn.active {
            color: var(--warning);
            transform: scale(1.1);
        }

        /* Saved Searches */
        .saved-searches {
            background: rgba(255, 255, 255, 0.05);
            border-radius: 8px;
            padding: 10px;
            margin-top: 15px;
        }

        .saved-search-item {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 8px 12px;
            background: rgba(255, 255, 255, 0.05);
            border-radius: 6px;
            margin-bottom: 6px;
            cursor: pointer;
            transition: background 0.2s;
        }

        .saved-search-item:hover {
            background: rgba(255, 255, 255, 0.1);
        }

        .saved-search-item .info {
            display: flex;
            flex-direction: column;
        }

        .saved-search-item .name {
            font-weight: 600;
            color: #fff;
        }

        .saved-search-item .meta {
            font-size: 0.75rem;
            color: rgba(255, 255, 255, 0.5);
        }

        /* Number Animation */
        .stat-value.animate {
            transition: all 0.5s ease-out;
        }

        /* Keyboard shortcut hints */
        .shortcut-hint {
            font-size: 0.7rem;
            color: rgba(255, 255, 255, 0.4);
            margin-top: 4px;
        }

        kbd {
            background: rgba(255, 255, 255, 0.1);
            padding: 2px 6px;
            border-radius: 4px;
            font-size: 0.7rem;
        }

        .insight-item {
            background: rgba(52, 131, 250, 0.1);
            border-left: 4px solid var(--secondary);
            border-radius: 8px;
            padding: 1rem 1.25rem;
            margin-bottom: 1rem;
        }

        .insight-category {
            font-size: 0.75rem;
            text-transform: uppercase;
            color: var(--secondary);
            font-weight: 700;
            letter-spacing: 1px;
        }

        .metric-bar {
            height: 8px;
            background: rgba(255, 255, 255, 0.1);
            border-radius: 4px;
            overflow: hidden;
        }

        .metric-bar-fill {
            height: 100%;
            border-radius: 4px;
            transition: width 0.5s ease;
        }

        .table-dark-custom {
            background: transparent;
        }

        .table-dark-custom th {
            background: rgba(255, 255, 255, 0.1);
            color: var(--primary);
            font-weight: 600;
            border: none;
        }

        .table-dark-custom td {
            background: transparent;
            color: rgba(255, 255, 255, 0.9);
            border-color: rgba(255, 255, 255, 0.1);
        }

        .nav-tabs-custom {
            border: none;
            gap: 0.5rem;
        }

        .nav-tabs-custom .nav-link {
            border: none;
            background: rgba(255, 255, 255, 0.1);
            color: rgba(255, 255, 255, 0.7);
            border-radius: 8px;
            padding: 0.75rem 1.5rem;
            font-weight: 600;
            transition: all 0.3s ease;
        }

        .nav-tabs-custom .nav-link:hover {
            background: rgba(255, 255, 255, 0.2);
            color: #fff;
        }

        .nav-tabs-custom .nav-link.active {
            background: var(--primary);
            color: var(--dark);
        }

        .section-title {
            color: #fff;
            font-weight: 700;
            margin-bottom: 1.5rem;
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }

        .section-title i {
            color: var(--primary);
        }

        #results-section {
            display: none;
        }

        .fade-in {
            animation: fadeIn 0.5s ease forwards;
        }

        @keyframes fadeIn {
            from {
                opacity: 0;
                transform: translateY(20px);
            }

            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        @keyframes slideIn {
            from {
                opacity: 0;
                transform: translateX(100px);
            }

            to {
                opacity: 1;
                transform: translateX(0);
            }
        }

        @keyframes slideOut {
            from {
                opacity: 1;
                transform: translateX(0);
            }

            to {
                opacity: 0;
                transform: translateX(100px);
            }
        }

        /* Export buttons */
        .export-buttons {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
        }

        .export-buttons .btn {
            display: flex;
            align-items: center;
            gap: 6px;
        }

        /* Print styles */
        @media print {
            body {
                background: white !important;
                color: black !important;
            }

            .btn,
            .form-control,
            .form-select,
            .export-buttons,
            .filters-panel,
            #recentSearchesContainer,
            .category-tags,
            nav,
            footer {
                display: none !important;
            }

            .card,
            .stat-card,
            .opportunity-card {
                background: white !important;
                border: 1px solid #ddd !important;
                color: black !important;
                box-shadow: none !important;
            }

            .card-body *,
            .stat-value,
            .stat-label {
                color: black !important;
            }

            #results-section {
                display: block !important;
            }

            .text-white,
            .text-white-50,
            .text-success,
            .text-info,
            .text-warning {
                color: black !important;
            }
        }

        .comparison-vs {
            width: 60px;
            height: 60px;
            background: var(--primary);
            color: var(--dark);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 900;
            font-size: 1.5rem;
            margin: 0 auto;
        }
    </style>
</head>

<body>
    <!-- Loading Overlay -->
    <div class="loading-overlay" id="loadingOverlay" style="display: none;">
        <div class="loading-spinner"></div>
        <div class="loading-text" id="loadingText">Analisando mercado...</div>

        <!-- Progress Steps -->
        <div class="progress-steps" id="progressSteps">
            <div class="progress-step active" data-step="1">
                <div class="step-icon"><i class="fas fa-search"></i></div>
                <div class="step-label">Buscando</div>
            </div>
            <div class="progress-step" data-step="2">
                <div class="step-icon"><i class="fas fa-filter"></i></div>
                <div class="step-label">Filtrando</div>
            </div>
            <div class="progress-step" data-step="3">
                <div class="step-icon"><i class="fas fa-users"></i></div>
                <div class="step-label">Sellers</div>
            </div>
            <div class="progress-step" data-step="4">
                <div class="step-icon"><i class="fas fa-chart-line"></i></div>
                <div class="step-label">Análise</div>
            </div>
            <div class="progress-step" data-step="5">
                <div class="step-icon"><i class="fas fa-check"></i></div>
                <div class="step-label">Pronto</div>
            </div>
        </div>

        <div class="loading-progress">
            <div class="loading-progress-bar" id="loadingProgressBar"></div>
        </div>
        <div class="loading-stats" id="loadingStats"></div>
    </div>

    <!-- Header -->
    <header class="research-header">
        <div class="container">
            <div class="d-flex align-items-center justify-content-between flex-wrap gap-3">
                <div>
                    <h1 class="research-title">
                        <i class="fas fa-microscope me-2"></i>
                        Deep Research
                    </h1>
                    <p class="research-subtitle mb-0">
                        Análise profunda de marcas, sellers, preços, fretes e oportunidades de mercado
                    </p>
                </div>
                <div class="d-flex gap-2 align-items-center">
                    <!-- ML Connection Status -->
                    <div class="ml-status" id="mlStatus" title="Status da conexão com Mercado Livre">
                        <i class="fas fa-circle-notch fa-spin"></i>
                        <span>Verificando...</span>
                    </div>
                    <a href="/dashboard" class="btn btn-dark">
                        <i class="fas fa-arrow-left me-2"></i> Voltar
                    </button>
                </div>
            </div>
        </div>
    </header>

    <div class="container pb-5">
        <!-- Search Form -->
        <div class="search-form">
            <form id="researchForm">
                <div class="row g-3">
                    <div class="col-md-5">
                        <label for="categorySelect">
                            <i class="fas fa-folder-tree me-1"></i> Categoria
                        </label>
                        <select class="form-select" id="categorySelect" required>
                            <option value="">Digite para buscar uma categoria...</option>
                        </select>
                        <input type="hidden" id="categoryId">

                        <!-- Popular Categories -->
                        <div class="popular-categories mt-2">
                            <span class="text-white-50 me-2" style="font-size: 0.8rem;">Populares:</span>
                            <span class="category-tag" data-category="MLB1071" data-name="Acessórios para Motos">
                                <i class="fas fa-motorcycle"></i> Motos
                            </span>
                            <span class="category-tag" data-category="MLB1000" data-name="Eletrônicos, Áudio e Vídeo">
                                <i class="fas fa-tv"></i> Eletrônicos
                            </span>
                            <span class="category-tag" data-category="MLB1648" data-name="Computadores">
                                <i class="fas fa-laptop"></i> Informática
                            </span>
                            <span class="category-tag" data-category="MLB1132" data-name="Brinquedos e Hobbies">
                                <i class="fas fa-gamepad"></i> Brinquedos
                            </span>
                            <span class="category-tag" data-category="MLB1168" data-name="Celulares e Telefones">
                                <i class="fas fa-mobile-alt"></i> Celulares
                            </span>
                            <span class="category-tag" data-category="MLB1574" data-name="Casa, Móveis e Decoração">
                                <i class="fas fa-couch"></i> Casa
                            </span>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <label for="brandSelect">
                            <i class="fas fa-tag me-1"></i> Marca
                        </label>
                        <select class="form-select" id="brandSelect" required>
                            <option value="">Selecione uma categoria primeiro...</option>
                        </select>
                        <input type="hidden" id="brand">
                        <small class="text-white-50">Selecione ou digite a marca</small>
                    </div>
                    <div class="col-md-2">
                        <label for="maxItems">
                            <i class="fas fa-list-ol me-1"></i> Máx. Itens
                        </label>
                        <select class="form-select" id="maxItems">
                            <option value="100">100</option>
                            <option value="200">200</option>
                            <option value="500" selected>500</option>
                            <option value="1000">1000</option>
                        </select>
                    </div>
                    <div class="col-md-2 d-flex align-items-end gap-2">
                        <button type="submit" class="btn btn-research flex-grow-1">
                            <i class="fas fa-search me-2"></i> Pesquisar
                            <span class="shortcut-hint d-none d-lg-inline">
                                <kbd>Ctrl</kbd>+<kbd>Enter</kbd>
                            </span>
                        </button>
                        <button type="button" class="favorite-btn" id="favoriteBtn" data-action="toggle-favorite" title="Salvar pesquisa favorita">
                            <i class="far fa-heart"></i>
                        </button>
                        <button type="button" class="btn btn-outline-light btn-sm" id="shareBtn" onclick="copyShareLink()" title="Copiar link da pesquisa">
                            <i class="fas fa-share-alt"></i>
                        </button>
                    </div>
                </div>

                <!-- Saved Searches Quick Access -->
                <div class="saved-searches mt-3" id="savedSearchesContainer" style="display: none;">
                    <h6 class="saved-searches-title">
                        <i class="fas fa-heart me-2"></i> Pesquisas Favoritas
                    </h6>
                    <div class="saved-searches-list" id="savedSearchesList">
                        <!-- Dynamic content -->
                    </div>
                </div>

                <!-- Advanced Options -->
                <div class="row mt-3">
                    <div class="col-12">
                        <div class="form-check form-check-inline">
                            <input class="form-check-input" type="checkbox" id="includeSellers" checked>
                            <label class="form-check-label text-white" for="includeSellers">
                                <i class="fas fa-users me-1"></i> Detalhes de Sellers
                            </label>
                        </div>
                        <div class="form-check form-check-inline">
                            <input class="form-check-input" type="checkbox" id="analyzeShipping" checked>
                            <label class="form-check-label text-white" for="analyzeShipping">
                                <i class="fas fa-truck me-1"></i> Análise de Frete
                            </label>
                        </div>
                        <div class="form-check form-check-inline">
                            <input class="form-check-input" type="checkbox" id="calculateCommissions" checked>
                            <label class="form-check-label text-white" for="calculateCommissions">
                                <i class="fas fa-percent me-1"></i> Cálculo de Comissões
                            </label>
                        </div>

                        <!-- Filtros Avançados Toggle -->
                        <span class="filters-toggle ms-4" id="filtersToggle" data-action="toggle-filters">
                            <i class="fas fa-sliders-h me-1"></i> Filtros Avançados
                            <i class="fas fa-chevron-down ms-1"></i>
                        </span>
                    </div>
                </div>

                <!-- Advanced Filters Panel -->
                <div class="filters-panel" id="filtersPanel" style="display: none;">
                    <div class="row g-3">
                        <!-- Faixa de Preço -->
                        <div class="col-md-4">
                            <div class="filter-group">
                                <label><i class="fas fa-dollar-sign me-1"></i> Faixa de Preço</label>
                                <div class="price-range-inputs">
                                    <input type="number" class="filter-input" id="priceMin" placeholder="Min" min="0">
                                    <span>até</span>
                                    <input type="number" class="filter-input" id="priceMax" placeholder="Max" min="0">
                                </div>
                            </div>
                        </div>

                        <!-- Condição -->
                        <div class="col-md-4">
                            <div class="filter-group">
                                <label><i class="fas fa-box me-1"></i> Condição</label>
                                <div class="filter-chips">
                                    <span class="filter-chip active" data-filter="condition" data-value="all">
                                        <i class="fas fa-check-double"></i> Todos
                                    </span>
                                    <span class="filter-chip" data-filter="condition" data-value="new">
                                        <i class="fas fa-sparkles"></i> Novo
                                    </span>
                                    <span class="filter-chip" data-filter="condition" data-value="used">
                                        <i class="fas fa-recycle"></i> Usado
                                    </span>
                                </div>
                            </div>
                        </div>

                        <!-- Tipo de Frete -->
                        <div class="col-md-4">
                            <div class="filter-group">
                                <label><i class="fas fa-truck me-1"></i> Tipo de Frete</label>
                                <div class="filter-chips">
                                    <span class="filter-chip active" data-filter="shipping" data-value="all">
                                        <i class="fas fa-check-double"></i> Todos
                                    </span>
                                    <span class="filter-chip" data-filter="shipping" data-value="free">
                                        <i class="fas fa-gift"></i> Grátis
                                    </span>
                                    <span class="filter-chip" data-filter="shipping" data-value="full">
                                        <i class="fas fa-warehouse"></i> Full
                                    </span>
                                </div>
                            </div>
                        </div>

                        <!-- Tipo de Listagem -->
                        <div class="col-md-4">
                            <div class="filter-group">
                                <label><i class="fas fa-list-alt me-1"></i> Tipo de Listagem</label>
                                <div class="filter-chips">
                                    <span class="filter-chip active" data-filter="listing" data-value="all">
                                        <i class="fas fa-check-double"></i> Todos
                                    </span>
                                    <span class="filter-chip" data-filter="listing" data-value="catalog">
                                        <i class="fas fa-book"></i> Catálogo
                                    </span>
                                    <span class="filter-chip" data-filter="listing" data-value="common">
                                        <i class="fas fa-tag"></i> Comum
                                    </span>
                                </div>
                            </div>
                        </div>

                        <!-- Reputação do Seller -->
                        <div class="col-md-4">
                            <div class="filter-group">
                                <label><i class="fas fa-star me-1"></i> Reputação do Seller</label>
                                <div class="filter-chips">
                                    <span class="filter-chip active" data-filter="reputation" data-value="all">
                                        <i class="fas fa-check-double"></i> Todos
                                    </span>
                                    <span class="filter-chip" data-filter="reputation" data-value="mercadolider">
                                        <i class="fas fa-crown"></i> MercadoLíder
                                    </span>
                                    <span class="filter-chip" data-filter="reputation" data-value="platinum">
                                        <i class="fas fa-medal"></i> Platinum
                                    </span>
                                </div>
                            </div>
                        </div>

                        <!-- Ordenação -->
                        <div class="col-md-4">
                            <div class="filter-group">
                                <label><i class="fas fa-sort me-1"></i> Ordenar por</label>
                                <select class="filter-input" id="sortBy">
                                    <option value="relevance">Relevância</option>
                                    <option value="price_asc">Menor Preço</option>
                                    <option value="price_desc">Maior Preço</option>
                                    <option value="sold_quantity">Mais Vendidos</option>
                                </select>
                            </div>
                        </div>
                    </div>

                    <!-- Botões de Ação dos Filtros -->
                    <div class="row mt-3">
                        <div class="col-12 text-end">
                            <button type="button" class="btn btn-outline-secondary btn-sm me-2" onclick="clearFilters()">
                                <i class="fas fa-eraser me-1"></i> Limpar Filtros
                            </button>
                            <button type="button" class="btn btn-outline-primary btn-sm" onclick="applyFilters()">
                                <i class="fas fa-filter me-1"></i> Aplicar Filtros
                            </button>
                        </div>
                    </div>
                </div>
            </form>

            <!-- Recent Searches -->
            <div class="recent-searches mt-4" id="recentSearchesContainer" style="display: none;">
                <h6 class="text-white mb-3">
                    <i class="fas fa-history me-2 text-primary"></i> Pesquisas Recentes
                </h6>
                <div id="recentSearchesList">
                    <!-- Dynamic content -->
                </div>
            </div>
        </div>

        <!-- Compare Brands -->
        <div class="dark-card">
            <h5 class="text-white mb-3">
                <i class="fas fa-balance-scale me-2 text-warning"></i>
                Comparar Marcas
            </h5>
            <form id="compareForm">
                <div class="row g-3">
                    <div class="col-md-3">
                        <select class="form-select" id="compareCategorySelect">
                            <option value="">Selecione a categoria...</option>
                        </select>
                        <input type="hidden" id="compareCategoryId">
                    </div>
                    <div class="col-md-3">
                        <select class="form-select" id="compareBrand1Select">
                            <option value="">Marca 1...</option>
                        </select>
                        <input type="hidden" id="compareBrand1">
                    </div>
                    <div class="col-md-3">
                        <select class="form-select" id="compareBrand2Select">
                            <option value="">Marca 2...</option>
                        </select>
                        <input type="hidden" id="compareBrand2">
                    </div>
                    <div class="col-md-3">
                        <button type="submit" class="btn btn-outline-warning w-100">
                            <i class="fas fa-balance-scale me-2"></i> Comparar
                        </button>
                    </div>
                </div>
            </form>
        </div>

        <!-- Results Section -->
        <section id="results-section">
            <!-- Action Bar -->
            <div class="row mb-3 fade-in">
                <div class="col-12 d-flex justify-content-between align-items-center flex-wrap gap-2">
                    <h2 class="text-white mb-0">
                        <i class="fas fa-chart-bar me-2"></i>Resultados da Análise
                    </h2>
                    <div class="export-buttons">
                        <button type="button" class="btn btn-outline-primary btn-sm" onclick="exportToCSV(lastSearchData)" title="Exportar lista de anúncios">
                            <i class="fas fa-file-csv"></i>
                            <span>Exportar CSV</span>
                        </button>
                        <button type="button" class="btn btn-outline-secondary btn-sm" onclick="exportToJSON(lastSearchData)" title="Exportar dados completos">
                            <i class="fas fa-file-code"></i>
                            <span>Exportar JSON</span>
                        </button>
                        <button type="button" class="btn btn-outline-success btn-sm" onclick="window.print()" title="Imprimir relatório">
                            <i class="fas fa-print"></i>
                            <span>Imprimir</span>
                        </button>
                    </div>
                </div>
            </div>

            <!-- Executive Summary -->
            <div class="row mb-4 fade-in" id="summarySection">
                <div class="col-12">
                    <h3 class="section-title">
                        <i class="fas fa-chart-pie"></i>
                        Resumo Executivo
                    </h3>
                </div>
                <div class="col-md-2">
                    <div class="stat-card">
                        <div class="stat-value" id="totalListings">-</div>
                        <div class="stat-label">Anúncios</div>
                    </div>
                </div>
                <div class="col-md-2">
                    <div class="stat-card">
                        <div class="stat-value" id="totalSellers">-</div>
                        <div class="stat-label">Sellers</div>
                    </div>
                </div>
                <div class="col-md-2">
                    <div class="stat-card">
                        <div class="stat-value text-success" id="avgPrice">-</div>
                        <div class="stat-label">Preço Médio</div>
                    </div>
                </div>
                <div class="col-md-2">
                    <div class="stat-card">
                        <div class="stat-value text-info" id="catalogRate">-</div>
                        <div class="stat-label">% Catálogo</div>
                    </div>
                </div>
                <div class="col-md-2">
                    <div class="stat-card">
                        <div class="stat-value text-warning" id="freeShippingRate">-</div>
                        <div class="stat-label">% Frete Grátis</div>
                    </div>
                </div>
                <div class="col-md-2">
                    <div class="stat-card">
                        <div class="stat-value text-danger" id="opportunitiesCount">-</div>
                        <div class="stat-label">Oportunidades</div>
                    </div>
                </div>
            </div>

            <!-- Tabs Navigation -->
            <ul class="nav nav-tabs-custom mb-4" role="tablist">
                <li class="nav-item">
                    <button class="nav-link active" data-bs-toggle="tab" data-bs-target="#tabOpportunities">
                        <i class="fas fa-lightbulb me-2"></i> Oportunidades
                    </button>
                </li>
                <li class="nav-item">
                    <button class="nav-link" data-bs-toggle="tab" data-bs-target="#tabSellers">
                        <i class="fas fa-users me-2"></i> Sellers
                    </button>
                </li>
                <li class="nav-item">
                    <button class="nav-link" data-bs-toggle="tab" data-bs-target="#tabPricing">
                        <i class="fas fa-dollar-sign me-2"></i> Preços
                    </button>
                </li>
                <li class="nav-item">
                    <button class="nav-link" data-bs-toggle="tab" data-bs-target="#tabShipping">
                        <i class="fas fa-truck me-2"></i> Frete
                    </button>
                </li>
                <li class="nav-item">
                    <button class="nav-link" data-bs-toggle="tab" data-bs-target="#tabCommissions">
                        <i class="fas fa-receipt me-2"></i> Comissões
                    </button>
                </li>
                <li class="nav-item">
                    <button class="nav-link" data-bs-toggle="tab" data-bs-target="#tabInsights">
                        <i class="fas fa-brain me-2"></i> Insights
                    </button>
                </li>
            </ul>

            <!-- Tab Content -->
            <div class="tab-content">
                <!-- Opportunities Tab -->
                <div class="tab-pane fade show active" id="tabOpportunities">
                    <div class="dark-card fade-in">
                        <h5 class="text-white mb-4">
                            <i class="fas fa-bullseye me-2 text-success"></i>
                            Oportunidades Identificadas
                        </h5>
                        <div id="opportunitiesList">
                            <!-- Dynamic content -->
                        </div>
                    </div>
                </div>

                <!-- Sellers Tab -->
                <div class="tab-pane fade" id="tabSellers">
                    <div class="row">
                        <div class="col-md-4">
                            <div class="dark-card fade-in">
                                <h5 class="text-white mb-4">
                                    <i class="fas fa-chart-pie me-2 text-primary"></i>
                                    Concentração de Mercado
                                </h5>
                                <div id="marketConcentration">
                                    <div class="mb-3">
                                        <div class="d-flex justify-content-between text-white-50 mb-1">
                                            <span>Top 3 Sellers</span>
                                            <span id="top3Share">-</span>
                                        </div>
                                        <div class="metric-bar">
                                            <div class="metric-bar-fill bg-primary" id="top3Bar" style="width: 0%"></div>
                                        </div>
                                    </div>
                                    <div class="mb-3">
                                        <div class="d-flex justify-content-between text-white-50 mb-1">
                                            <span>Top 10 Sellers</span>
                                            <span id="top10Share">-</span>
                                        </div>
                                        <div class="metric-bar">
                                            <div class="metric-bar-fill bg-info" id="top10Bar" style="width: 0%"></div>
                                        </div>
                                    </div>
                                    <div class="text-center mt-4">
                                        <div class="text-white-50">Índice HHI</div>
                                        <div class="stat-value" id="hhiIndex">-</div>
                                        <small class="text-white-50" id="hhiInterpretation">-</small>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-8">
                            <div class="dark-card fade-in">
                                <h5 class="text-white mb-4">
                                    <i class="fas fa-trophy me-2 text-warning"></i>
                                    Top Sellers
                                </h5>
                                <div id="sellersList">
                                    <!-- Dynamic content -->
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Pricing Tab -->
                <div class="tab-pane fade" id="tabPricing">
                    <div class="row">
                        <div class="col-md-6">
                            <div class="dark-card fade-in">
                                <h5 class="text-white mb-4">
                                    <i class="fas fa-chart-bar me-2 text-success"></i>
                                    Distribuição de Preços
                                </h5>
                                <div class="chart-container">
                                    <canvas id="priceDistributionChart"></canvas>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="dark-card fade-in">
                                <h5 class="text-white mb-4">
                                    <i class="fas fa-tags me-2 text-info"></i>
                                    Estatísticas de Preço
                                </h5>
                                <table class="table table-dark-custom">
                                    <tbody>
                                        <tr>
                                            <td>Preço Mínimo</td>
                                            <td class="text-end fw-bold" id="priceMin">-</td>
                                        </tr>
                                        <tr>
                                            <td>Preço Máximo</td>
                                            <td class="text-end fw-bold" id="priceMax">-</td>
                                        </tr>
                                        <tr>
                                            <td>Preço Médio</td>
                                            <td class="text-end fw-bold text-success" id="priceAvg">-</td>
                                        </tr>
                                        <tr>
                                            <td>Mediana</td>
                                            <td class="text-end fw-bold" id="priceMedian">-</td>
                                        </tr>
                                        <tr>
                                            <td>Percentil 25</td>
                                            <td class="text-end" id="priceP25">-</td>
                                        </tr>
                                        <tr>
                                            <td>Percentil 75</td>
                                            <td class="text-end" id="priceP75">-</td>
                                        </tr>
                                    </tbody>
                                </table>

                                <hr class="border-secondary">

                                <h6 class="text-white mt-4 mb-3">Catálogo vs Comum</h6>
                                <div id="catalogVsCommon">
                                    <div class="row text-center">
                                        <div class="col-6">
                                            <div class="text-white-50">Catálogo</div>
                                            <div class="h4 text-primary" id="catalogAvgPrice">-</div>
                                        </div>
                                        <div class="col-6">
                                            <div class="text-white-50">Comum</div>
                                            <div class="h4 text-warning" id="commonAvgPrice">-</div>
                                        </div>
                                    </div>
                                    <div class="text-center mt-2">
                                        <small class="text-white-50" id="priceGapInsight">-</small>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Shipping Tab -->
                <div class="tab-pane fade" id="tabShipping">
                    <div class="row">
                        <div class="col-md-6">
                            <div class="dark-card fade-in">
                                <h5 class="text-white mb-4">
                                    <i class="fas fa-shipping-fast me-2 text-primary"></i>
                                    Visão Geral de Frete
                                </h5>
                                <div class="chart-container">
                                    <canvas id="shippingChart"></canvas>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="dark-card fade-in">
                                <h5 class="text-white mb-4">
                                    <i class="fas fa-warehouse me-2 text-warning"></i>
                                    Tipos de Logística
                                </h5>
                                <div class="chart-container">
                                    <canvas id="logisticsChart"></canvas>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="dark-card fade-in mt-3">
                        <h5 class="text-white mb-4">
                            <i class="fas fa-info-circle me-2 text-info"></i>
                            Insights de Frete
                        </h5>
                        <div id="shippingInsights">
                            <!-- Dynamic content -->
                        </div>
                    </div>
                </div>

                <!-- Commissions Tab -->
                <div class="tab-pane fade" id="tabCommissions">
                    <div class="row">
                        <div class="col-md-6">
                            <div class="dark-card fade-in">
                                <h5 class="text-white mb-4">
                                    <i class="fas fa-money-bill-wave me-2 text-success"></i>
                                    Resumo de Comissões
                                </h5>
                                <table class="table table-dark-custom">
                                    <tbody>
                                        <tr>
                                            <td>Receita Total Estimada</td>
                                            <td class="text-end fw-bold text-success" id="totalRevenue">-</td>
                                        </tr>
                                        <tr>
                                            <td>Comissão ML Total</td>
                                            <td class="text-end fw-bold text-danger" id="totalCommission">-</td>
                                        </tr>
                                        <tr>
                                            <td>Taxa Mercado Pago</td>
                                            <td class="text-end fw-bold text-warning" id="totalPaymentFee">-</td>
                                        </tr>
                                        <tr>
                                            <td>Total de Taxas</td>
                                            <td class="text-end fw-bold text-danger" id="totalFees">-</td>
                                        </tr>
                                        <tr>
                                            <td>Taxa Efetiva Média</td>
                                            <td class="text-end fw-bold" id="effectiveRate">-</td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="dark-card fade-in">
                                <h5 class="text-white mb-4">
                                    <i class="fas fa-chart-pie me-2 text-primary"></i>
                                    Por Tipo de Listagem
                                </h5>
                                <div class="chart-container">
                                    <canvas id="commissionsChart"></canvas>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="dark-card fade-in mt-3">
                        <h5 class="text-white mb-4">
                            <i class="fas fa-calculator me-2 text-warning"></i>
                            Tabela de Comissões ML (Referência)
                        </h5>
                        <table class="table table-dark-custom table-sm">
                            <thead>
                                <tr>
                                    <th>Tipo de Listagem</th>
                                    <th>Comissão</th>
                                    <th>Qtd. Anúncios</th>
                                </tr>
                            </thead>
                            <tbody id="commissionRatesTable">
                                <!-- Dynamic content -->
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- Insights Tab -->
                <div class="tab-pane fade" id="tabInsights">
                    <div class="dark-card fade-in">
                        <h5 class="text-white mb-4">
                            <i class="fas fa-brain me-2 text-primary"></i>
                            Insights Estratégicos
                        </h5>
                        <div id="insightsList">
                            <!-- Dynamic content -->
                        </div>
                    </div>
                </div>
            </div>
        </section>

        <!-- Comparison Results -->
        <section id="comparison-section" style="display: none;">
            <div class="dark-card fade-in">
                <h3 class="section-title">
                    <i class="fas fa-balance-scale"></i>
                    Comparação de Marcas
                </h3>
                <div class="row">
                    <div class="col-md-5">
                        <div class="glass-card text-center">
                            <h4 id="brand1Name">Marca 1</h4>
                            <div id="brand1Stats">
                                <!-- Dynamic content -->
                            </div>
                        </div>
                    </div>
                    <div class="col-md-2 d-flex align-items-center justify-content-center">
                        <div class="comparison-vs">VS</div>
                    </div>
                    <div class="col-md-5">
                        <div class="glass-card text-center">
                            <h4 id="brand2Name">Marca 2</h4>
                            <div id="brand2Stats">
                                <!-- Dynamic content -->
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </section>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script nonce="<?= CSP_NONCE ?>">

        // Charts instances
        let priceDistributionChart = null;
        let shippingChart = null;
        let logisticsChart = null;
        let commissionsChart = null;

        // Category cache
        let categoryCache = {};
        let brandCache = {};

        // Recent searches storage key
        const RECENT_SEARCHES_KEY = 'deep_research_recent_searches';
        const FAVORITE_SEARCHES_KEY = 'deep_research_favorites';
        const MAX_RECENT_SEARCHES = 5;
        const MAX_FAVORITE_SEARCHES = 10;

        // Last search data for export
        let lastSearchData = null;

        // Current step in research process
        let currentStep = 0;

        // Advanced Filters State
        let activeFilters = {
            priceMin: null,
            priceMax: null,
            condition: 'all',
            shipping: 'all',
            listing: 'all',
            reputation: 'all',
            sortBy: 'relevance'
        };

        // Initialize Select2 for category selection
        $(document).ready(function() {
            initializeCategorySelect();
            initializeBrandSelect();
            initializeCompareSelects();
            loadRecentSearches();
            loadFavoriteSearches();
            setupPopularCategoryTags();
            setupFilterChips();
            setupPriceInputListeners();
            setupKeyboardShortcuts();
            checkMLStatus();
            checkURLParams();
        });

        // Setup price input change listeners
        function setupPriceInputListeners() {
            const priceMin = document.getElementById('priceMin');
            const priceMax = document.getElementById('priceMax');
            const sortBy = document.getElementById('sortBy');

            if (priceMin) {
                priceMin.addEventListener('change', function() {
                    activeFilters.priceMin = this.value || null;
                    updateFilterBadge();
                });
            }

            if (priceMax) {
                priceMax.addEventListener('change', function() {
                    activeFilters.priceMax = this.value || null;
                    updateFilterBadge();
                });
            }

            if (sortBy) {
                sortBy.addEventListener('change', function() {
                    activeFilters.sortBy = this.value;
                    updateFilterBadge();
                });
            }
        }

        // Category Select2 with AJAX search
        function initializeCategorySelect() {
            $('#categorySelect').select2({
                theme: 'bootstrap-5',
                placeholder: 'Digite para buscar uma categoria...',
                allowClear: true,
                minimumInputLength: 2,
                ajax: {
                    url: '/api/categories/search',
                    dataType: 'json',
                    delay: 300,
                    data: function(params) {
                        return {
                            q: params.term
                        };
                    },
                    processResults: function(data) {
                        return {
                            results: (data || []).map(cat => ({
                                id: cat.id,
                                text: cat.name,
                                path: cat.path_from_root || []
                            }))
                        };
                    },
                    cache: true
                },
                templateResult: formatCategoryResult,
                templateSelection: formatCategorySelection
            });

            // Update hidden field and load brands when category changes
            $('#categorySelect').on('select2:select', function(e) {
                const categoryId = e.params.data.id;
                const categoryName = e.params.data.text;
                $('#categoryId').val(categoryId);
                categoryCache[categoryId] = categoryName;
                loadBrandsForCategory(categoryId, '#brandSelect');
            });

            $('#categorySelect').on('select2:clear', function() {
                $('#categoryId').val('');
                $('#brandSelect').empty().append('<option value="">Selecione uma categoria primeiro...</option>');
                $('#brand').val('');
            });
        }

        // Brand Select2 - loads based on category
        function initializeBrandSelect() {
            $('#brandSelect').select2({
                theme: 'bootstrap-5',
                placeholder: 'Selecione uma categoria primeiro...',
                allowClear: true,
                tags: true, // Allow custom input
                createTag: function(params) {
                    return {
                        id: params.term,
                        text: params.term,
                        newTag: true
                    };
                }
            });

            $('#brandSelect').on('select2:select', function(e) {
                $('#brand').val(e.params.data.id);
            });

            $('#brandSelect').on('select2:clear', function() {
                $('#brand').val('');
            });
        }

        // Initialize compare form selects
        function initializeCompareSelects() {
            // Compare Category Select
            $('#compareCategorySelect').select2({
                theme: 'bootstrap-5',
                placeholder: 'Selecione a categoria...',
                allowClear: true,
                minimumInputLength: 2,
                ajax: {
                    url: '/api/categories/search',
                    dataType: 'json',
                    delay: 300,
                    data: function(params) {
                        return {
                            q: params.term
                        };
                    },
                    processResults: function(data) {
                        return {
                            results: (data || []).map(cat => ({
                                id: cat.id,
                                text: cat.name
                            }))
                        };
                    },
                    cache: true
                }
            });

            $('#compareCategorySelect').on('select2:select', function(e) {
                const categoryId = e.params.data.id;
                $('#compareCategoryId').val(categoryId);
                loadBrandsForCategory(categoryId, '#compareBrand1Select');
                loadBrandsForCategory(categoryId, '#compareBrand2Select');
            });

            // Compare Brand Selects
            ['#compareBrand1Select', '#compareBrand2Select'].forEach((selector, idx) => {
                $(selector).select2({
                    theme: 'bootstrap-5',
                    placeholder: `Marca ${idx + 1}...`,
                    allowClear: true,
                    tags: true
                });

                $(selector).on('select2:select', function(e) {
                    $(`#compareBrand${idx + 1}`).val(e.params.data.id);
                });
            });
        }

        // Load brands for a category
        async function loadBrandsForCategory(categoryId, selectSelector) {
            const $select = $(selectSelector);
            $select.prop('disabled', true);

            try {
                const brands = await requestJson(`/api/categories/${categoryId}/brands`);

                $select.empty();
                $select.append('<option value="">Selecione ou digite uma marca...</option>');

                if (Array.isArray(brands) && brands.length > 0) {
                    brands.forEach(brand => {
                        const name = brand.name || brand.id;
                        const count = brand.results ? ` (${brand.results} anúncios)` : '';
                        $select.append(`<option value="${name}">${name}${count}</option>`);
                    });
                    brandCache[categoryId] = brands;
                }
            } catch (error) {
                console.error('Erro ao carregar marcas:', error);
            } finally {
                $select.prop('disabled', false);
            }
        }

        // Format category result in dropdown
        function formatCategoryResult(category) {
            if (category.loading) return category.text;

            let html = `<div class="category-option">
                <span class="category-name">${category.text}</span>`;

            if (category.path && category.path.length > 0) {
                const pathNames = category.path.map(p => p.name).join(' > ');
                html += `<br><span class="category-path">${pathNames}</span>`;
            }

            html += '</div>';
            return $(html);
        }

        // Format category selection
        function formatCategorySelection(category) {
            return category.text || category.id;
        }

        // Popular category tags click handler
        function setupPopularCategoryTags() {
            $('.category-tag').on('click', function() {
                const categoryId = $(this).data('category');
                const categoryName = $(this).data('name');

                // Set the category in Select2
                const newOption = new Option(categoryName, categoryId, true, true);
                $('#categorySelect').append(newOption).trigger('change');
                $('#categoryId').val(categoryId);
                categoryCache[categoryId] = categoryName;

                // Load brands
                loadBrandsForCategory(categoryId, '#brandSelect');
            });
        }

        // ========== FILTROS AVANÇADOS ==========

        // Toggle filtros avançados
        function toggleFilters() {
            const panel = document.getElementById('filtersPanel');
            const toggle = document.getElementById('filtersToggle');

            if (panel.style.display === 'none') {
                panel.style.display = 'block';
                toggle.classList.add('active');
            } else {
                panel.style.display = 'none';
                toggle.classList.remove('active');
            }
        }

        // Update filter badge counter
        function updateFilterBadge() {
            const count = Object.values(activeFilters).filter(v => v && v !== 'all' && v !== 'relevance' && v !== null).length;
            const toggle = document.getElementById('filtersToggle');
            let badge = toggle.querySelector('.filter-badge');

            if (count > 0) {
                if (!badge) {
                    badge = document.createElement('span');
                    badge.className = 'filter-badge';
                    badge.style.cssText = 'background: var(--primary); color: var(--dark); border-radius: 50%; width: 18px; height: 18px; font-size: 11px; display: inline-flex; align-items: center; justify-content: center; margin-left: 6px;';
                    toggle.appendChild(badge);
                }
                badge.textContent = count;
            } else if (badge) {
                badge.remove();
            }
        }

        // Setup filter chips click handlers
        function setupFilterChips() {
            document.querySelectorAll('.filter-chip').forEach(chip => {
                chip.addEventListener('click', function() {
                    const filterType = this.dataset.filter;
                    const value = this.dataset.value;

                    // Remove active from siblings
                    this.parentElement.querySelectorAll('.filter-chip').forEach(c => c.classList.remove('active'));

                    // Add active to clicked
                    this.classList.add('active');

                    // Update filter state
                    activeFilters[filterType] = value;

                    // Update badge
                    updateFilterBadge();
                });
            });
        }

        // Apply filters
        function applyFilters() {
            activeFilters.priceMin = document.getElementById('priceMin').value || null;
            activeFilters.priceMax = document.getElementById('priceMax').value || null;
            activeFilters.sortBy = document.getElementById('sortBy').value;

            // Show active filters count
            const activeCount = Object.values(activeFilters).filter(v => v && v !== 'all' && v !== 'relevance').length;

            if (activeCount > 0) {
                showNotification(`${activeCount} filtro(s) aplicado(s)`, 'success');
            }
        }

        // Clear all filters
        function clearFilters() {
            // Reset state
            activeFilters = {
                priceMin: null,
                priceMax: null,
                condition: 'all',
                shipping: 'all',
                listing: 'all',
                reputation: 'all',
                sortBy: 'relevance'
            };

            // Reset inputs
            document.getElementById('priceMin').value = '';
            document.getElementById('priceMax').value = '';
            document.getElementById('sortBy').value = 'relevance';

            // Reset chips
            document.querySelectorAll('.filter-chip').forEach(chip => {
                chip.classList.remove('active');
                if (chip.dataset.value === 'all') {
                    chip.classList.add('active');
                }
            });

            // Update badge
            updateFilterBadge();

            showNotification('Filtros limpos', 'info');
        }

        // Get filters as query params
        function getFilterParams() {
            const params = {};

            if (activeFilters.priceMin) params.price_min = activeFilters.priceMin;
            if (activeFilters.priceMax) params.price_max = activeFilters.priceMax;
            if (activeFilters.condition !== 'all') params.condition = activeFilters.condition;
            if (activeFilters.shipping !== 'all') params.shipping = activeFilters.shipping;
            if (activeFilters.listing !== 'all') params.listing_type = activeFilters.listing;
            if (activeFilters.reputation !== 'all') params.seller_reputation = activeFilters.reputation;
            if (activeFilters.sortBy !== 'relevance') params.sort = activeFilters.sortBy;

            return params;
        }

        // Show notification toast
        function showNotification(message, type = 'info') {
            const colors = {
                success: 'var(--success)',
                error: 'var(--danger)',
                warning: 'var(--warning)',
                info: 'var(--secondary)'
            };

            const toast = document.createElement('div');
            toast.style.cssText = `
                position: fixed;
                bottom: 20px;
                right: 20px;
                background: ${colors[type]};
                color: #fff;
                padding: 12px 24px;
                border-radius: 8px;
                box-shadow: 0 4px 20px rgba(0,0,0,0.3);
                z-index: 9999;
                animation: slideIn 0.3s ease;
            `;
            toast.innerHTML = `<i class="fas fa-${type === 'success' ? 'check' : type === 'error' ? 'times' : 'info'}-circle me-2"></i>${message}`;

            document.body.appendChild(toast);

            setTimeout(() => {
                toast.style.animation = 'slideOut 0.3s ease';
                setTimeout(() => toast.remove(), 300);
            }, 3000);
        }

        // ========== EXPORTAÇÃO DE RESULTADOS ==========

        // Export results to CSV
        function exportToCSV(data, filename = 'deep_research_results.csv') {
            if (!data || !data.listings) {
                showNotification('Nenhum dado para exportar', 'warning');
                return;
            }

            const items = [
                ...(data.listings.catalog?.items || []),
                ...(data.listings.common?.items || [])
            ];

            if (items.length === 0) {
                showNotification('Nenhum anúncio para exportar', 'warning');
                return;
            }

            // CSV Headers
            const headers = ['ID', 'Título', 'Preço', 'Preço Original', 'Vendidos', 'Disponível', 'Condição', 'Tipo Listagem', 'Catálogo', 'Link'];

            // CSV Rows
            const rows = items.map(item => [
                item.id,
                `"${(item.title || '').replace(/"/g, '""')}"`,
                item.price,
                item.original_price || '',
                item.sold_quantity || 0,
                item.available_quantity || 0,
                item.condition || '',
                item.listing_type_id || '',
                item.catalog_product_id ? 'Sim' : 'Não',
                item.permalink || ''
            ]);

            // Build CSV
            const csv = [headers.join(','), ...rows.map(r => r.join(','))].join('\n');

            // Download
            const blob = new Blob(['\ufeff' + csv], {
                type: 'text/csv;charset=utf-8;'
            });
            const link = document.createElement('a');
            link.href = URL.createObjectURL(blob);
            link.download = filename;
            link.click();

            showNotification(`Exportado ${items.length} itens`, 'success');
        }

        // Export to JSON
        function exportToJSON(data, filename = 'deep_research_results.json') {
            if (!data) {
                showNotification('Nenhum dado para exportar', 'warning');
                return;
            }

            const blob = new Blob([JSON.stringify(data, null, 2)], {
                type: 'application/json'
            });
            const link = document.createElement('a');
            link.href = URL.createObjectURL(blob);
            link.download = filename;
            link.click();

            showNotification('Dados exportados em JSON', 'success');
        }

        // Recent Searches Functions
        function loadRecentSearches() {
            const searches = getRecentSearches();
            const container = document.getElementById('recentSearchesContainer');
            const list = document.getElementById('recentSearchesList');

            if (searches.length === 0) {
                container.style.display = 'none';
                return;
            }

            container.style.display = 'block';
            list.innerHTML = searches.map((search, index) => `
                <div class="recent-search-item" data-index="${index}">
                    <div class="search-info" onclick="applyRecentSearch(${index})">
                        <i class="fas fa-search"></i>
                        <div>
                            <strong class="text-white">${search.brand}</strong>
                            <span class="brand-badge ms-2">${search.categoryName}</span>
                            <div class="search-details">${search.date}</div>
                        </div>
                    </div>
                    <span class="remove-btn" onclick="removeRecentSearch(${index})">
                        <i class="fas fa-times"></i>
                    </span>
                </div>
            `).join('');
        }

        function getRecentSearches() {
            try {
                return JSON.parse(localStorage.getItem(RECENT_SEARCHES_KEY) || '[]');
            } catch {
                return [];
            }
        }

        function saveRecentSearch(categoryId, categoryName, brand) {
            const searches = getRecentSearches();
            const newSearch = {
                categoryId,
                categoryName,
                brand,
                date: new Date().toLocaleDateString('pt-BR')
            };

            // Remove duplicates
            const filtered = searches.filter(s =>
                !(s.categoryId === categoryId && s.brand === brand)
            );

            // Add to beginning
            filtered.unshift(newSearch);

            // Keep only last N searches
            const trimmed = filtered.slice(0, MAX_RECENT_SEARCHES);

            localStorage.setItem(RECENT_SEARCHES_KEY, JSON.stringify(trimmed));
            loadRecentSearches();
        }

        function applyRecentSearch(index) {
            const searches = getRecentSearches();
            const search = searches[index];

            if (!search) return;

            // Set category
            const newOption = new Option(search.categoryName, search.categoryId, true, true);
            $('#categorySelect').empty().append(newOption).trigger('change');
            $('#categoryId').val(search.categoryId);
            categoryCache[search.categoryId] = search.categoryName;

            // Load brands and set the brand
            loadBrandsForCategory(search.categoryId, '#brandSelect').then(() => {
                setTimeout(() => {
                    const brandOption = new Option(search.brand, search.brand, true, true);
                    $('#brandSelect').append(brandOption).trigger('change');
                    $('#brand').val(search.brand);
                }, 500);
            });
        }

        function removeRecentSearch(index) {
            event.stopPropagation();
            const searches = getRecentSearches();
            searches.splice(index, 1);
            localStorage.setItem(RECENT_SEARCHES_KEY, JSON.stringify(searches));
            loadRecentSearches();
        }

        // ===========================================
        // PROGRESS STEPS FUNCTIONS
        // ===========================================

        function updateProgressStep(step) {
            currentStep = step;
            const steps = document.querySelectorAll('.progress-step');
            const progressBar = document.querySelector('.loading-progress-bar .progress-bar');

            steps.forEach((stepEl, index) => {
                stepEl.classList.remove('active', 'completed');
                if (index < step) {
                    stepEl.classList.add('completed');
                } else if (index === step) {
                    stepEl.classList.add('active');
                }
            });

            // Update progress bar
            const progress = ((step + 1) / 5) * 100;
            if (progressBar) {
                progressBar.style.width = progress + '%';
            }

            // Update loading text based on step
            const stepTexts = [
                'Buscando anúncios no Mercado Livre...',
                'Aplicando filtros e ordenação...',
                'Analisando dados dos vendedores...',
                'Calculando métricas e oportunidades...',
                'Finalizando análise...'
            ];
            updateLoadingText(stepTexts[step] || stepTexts[0]);

            // Update stats
            updateLoadingStats(step);
        }

        function updateLoadingStats(step) {
            const statsEl = document.getElementById('loadingStats');
            if (!statsEl) return;

            const statsData = [{
                    items: '0',
                    time: '0s'
                },
                {
                    items: '~50',
                    time: '2s'
                },
                {
                    items: '~150',
                    time: '5s'
                },
                {
                    items: '~300',
                    time: '8s'
                },
                {
                    items: '~500',
                    time: '10s'
                }
            ];

            const stats = statsData[step] || statsData[0];
            statsEl.innerHTML = `
                <span><i class="fas fa-box me-1"></i> ${stats.items} itens</span>
                <span><i class="fas fa-clock me-1"></i> ~${stats.time}</span>
            `;
        }

        function resetProgressSteps() {
            currentStep = 0;
            const steps = document.querySelectorAll('.progress-step');
            steps.forEach(step => step.classList.remove('active', 'completed'));
            const progressBar = document.querySelector('.loading-progress-bar .progress-bar');
            if (progressBar) progressBar.style.width = '0%';
        }

        // ===========================================
        // ML STATUS FUNCTIONS
        // ===========================================

        async function checkMLStatus() {
            const statusEl = document.getElementById('mlStatus');
            if (!statusEl) return;

            statusEl.innerHTML = '<i class="fas fa-spinner fa-spin me-1"></i> Verificando...';
            statusEl.className = 'ml-status';

            try {
                const data = await requestJson('/api/auth/status');

                if (data.success && data.connected) {
                    statusEl.innerHTML = '<i class="fas fa-check-circle me-1"></i> ML Conectado';
                    statusEl.classList.add('connected');
                } else {
                    statusEl.innerHTML = '<i class="fas fa-exclamation-circle me-1"></i> ML Desconectado';
                    statusEl.classList.add('disconnected');
                    statusEl.onclick = () => window.location.href = '/auth/authorize';
                    statusEl.style.cursor = 'pointer';
                    statusEl.title = 'Clique para conectar';
                }
            } catch (error) {
                statusEl.innerHTML = '<i class="fas fa-question-circle me-1"></i> Status Desconhecido';
                statusEl.classList.add('disconnected');
            }
        }

        // ===========================================
        // FAVORITE SEARCHES FUNCTIONS
        // ===========================================

        function getFavoriteSearches() {
            try {
                return JSON.parse(localStorage.getItem(FAVORITE_SEARCHES_KEY)) || [];
            } catch {
                return [];
            }
        }

        function loadFavoriteSearches() {
            const favorites = getFavoriteSearches();
            const container = document.getElementById('savedSearchesContainer');
            const list = document.getElementById('savedSearchesList');

            if (favorites.length === 0) {
                container.style.display = 'none';
                return;
            }

            container.style.display = 'block';
            list.innerHTML = favorites.map((fav, index) => `
                <div class="saved-search-item" onclick="loadFavoriteSearch(${index})">
                    <div class="info">
                        <span class="name">
                            <i class="fas fa-heart text-danger me-2"></i>${fav.brand}
                        </span>
                        <span class="meta">${fav.categoryName}</span>
                    </div>
                    <button class="btn btn-link btn-sm text-danger p-0" onclick="removeFavoriteSearch(${index}); event.stopPropagation();" title="Remover favorito">
                        <i class="fas fa-times"></i>
                    </button>
                </div>
            `).join('');

            // Update favorite button state if current search is favorited
            updateFavoriteButtonState();
        }

        function toggleFavorite() {
            const categoryId = document.getElementById('categoryId').value.trim();
            const brand = document.getElementById('brand').value.trim();

            if (!categoryId || !brand) {
                showNotification('Preencha categoria e marca para favoritar', 'warning');
                return;
            }

            const categoryName = categoryCache[categoryId] || categoryId;
            const favorites = getFavoriteSearches();

            // Check if already favorited
            const existingIndex = favorites.findIndex(
                f => f.categoryId === categoryId && f.brand.toLowerCase() === brand.toLowerCase()
            );

            const btn = document.getElementById('favoriteBtn');

            if (existingIndex !== -1) {
                // Remove from favorites
                favorites.splice(existingIndex, 1);
                btn.classList.remove('active');
                btn.innerHTML = '<i class="far fa-heart"></i>';
                showNotification('Pesquisa removida dos favoritos', 'info');
            } else {
                // Add to favorites
                if (favorites.length >= MAX_FAVORITE_SEARCHES) {
                    favorites.shift(); // Remove oldest
                }
                favorites.push({
                    categoryId,
                    categoryName,
                    brand,
                    savedAt: new Date().toISOString()
                });
                btn.classList.add('active');
                btn.innerHTML = '<i class="fas fa-heart"></i>';
                showNotification('Pesquisa adicionada aos favoritos!', 'success');
            }

            localStorage.setItem(FAVORITE_SEARCHES_KEY, JSON.stringify(favorites));
            loadFavoriteSearches();
        }

        function updateFavoriteButtonState() {
            const categoryId = document.getElementById('categoryId').value.trim();
            const brand = document.getElementById('brand').value.trim();
            const btn = document.getElementById('favoriteBtn');

            if (!btn || !categoryId || !brand) return;

            const favorites = getFavoriteSearches();
            const isFavorited = favorites.some(
                f => f.categoryId === categoryId && f.brand.toLowerCase() === brand.toLowerCase()
            );

            if (isFavorited) {
                btn.classList.add('active');
                btn.innerHTML = '<i class="fas fa-heart"></i>';
            } else {
                btn.classList.remove('active');
                btn.innerHTML = '<i class="far fa-heart"></i>';
            }
        }

        function loadFavoriteSearch(index) {
            const favorites = getFavoriteSearches();
            const fav = favorites[index];
            if (!fav) return;

            // Same logic as loadRecentSearch
            loadSearchIntoForm(fav);
        }

        function removeFavoriteSearch(index) {
            event.stopPropagation();
            const favorites = getFavoriteSearches();
            favorites.splice(index, 1);
            localStorage.setItem(FAVORITE_SEARCHES_KEY, JSON.stringify(favorites));
            loadFavoriteSearches();
            updateFavoriteButtonState();
            showNotification('Favorito removido', 'info');
        }

        function loadSearchIntoForm(search) {
            // Set category
            const newOption = new Option(search.categoryName, search.categoryId, true, true);
            $('#categorySelect').empty().append(newOption).trigger('change');
            $('#categoryId').val(search.categoryId);
            categoryCache[search.categoryId] = search.categoryName;

            // Load brands and set the brand
            loadBrandsForCategory(search.categoryId, '#brandSelect').then(() => {
                setTimeout(() => {
                    const brandOption = new Option(search.brand, search.brand, true, true);
                    $('#brandSelect').append(brandOption).trigger('change');
                    $('#brand').val(search.brand);
                    updateFavoriteButtonState();
                }, 500);
            });
        }

        // ===========================================
        // SHARE LINK FUNCTIONS
        // ===========================================

        function copyShareLink() {
            const categoryId = document.getElementById('categoryId').value.trim();
            const brand = document.getElementById('brand').value.trim();

            if (!categoryId || !brand) {
                showNotification('Preencha categoria e marca para compartilhar', 'warning');
                return;
            }

            // Build shareable URL
            const params = new URLSearchParams({
                cat: categoryId,
                brand: brand,
                max: document.getElementById('maxItems').value
            });

            // Add active filters
            if (activeFilters.priceMin) params.append('pmin', activeFilters.priceMin);
            if (activeFilters.priceMax) params.append('pmax', activeFilters.priceMax);
            if (activeFilters.condition !== 'all') params.append('cond', activeFilters.condition);
            if (activeFilters.shipping !== 'all') params.append('ship', activeFilters.shipping);

            const shareUrl = `${window.location.origin}${window.location.pathname}?${params.toString()}`;

            // Copy to clipboard
            navigator.clipboard.writeText(shareUrl).then(() => {
                showNotification('Link copiado para a área de transferência!', 'success');

                // Visual feedback
                const btn = document.getElementById('shareBtn');
                const originalIcon = btn.innerHTML;
                btn.innerHTML = '<i class="fas fa-check"></i>';
                setTimeout(() => btn.innerHTML = originalIcon, 2000);
            }).catch(() => {
                // Fallback for older browsers
                const input = document.createElement('input');
                input.value = shareUrl;
                document.body.appendChild(input);
                input.select();
                document.execCommand('copy');
                document.body.removeChild(input);
                showNotification('Link copiado!', 'success');
            });
        }

        function checkURLParams() {
            const params = new URLSearchParams(window.location.search);

            if (params.has('cat') && params.has('brand')) {
                const categoryId = params.get('cat');
                const brand = params.get('brand');

                // Set category (we need to fetch the name)
                requestJson(`/api/categories/${categoryId}`)
                    .then(data => {
                        const categoryName = data.name || categoryId;
                        const newOption = new Option(categoryName, categoryId, true, true);
                        $('#categorySelect').empty().append(newOption).trigger('change');
                        $('#categoryId').val(categoryId);
                        categoryCache[categoryId] = categoryName;

                        // Set brand
                        setTimeout(() => {
                            const brandOption = new Option(brand, brand, true, true);
                            $('#brandSelect').append(brandOption).trigger('change');
                            $('#brand').val(brand);
                            updateFavoriteButtonState();
                        }, 500);

                        // Set max items
                        if (params.has('max')) {
                            document.getElementById('maxItems').value = params.get('max');
                        }

                        // Set filters
                        if (params.has('pmin')) {
                            document.getElementById('priceMin').value = params.get('pmin');
                            activeFilters.priceMin = params.get('pmin');
                        }
                        if (params.has('pmax')) {
                            document.getElementById('priceMax').value = params.get('pmax');
                            activeFilters.priceMax = params.get('pmax');
                        }

                        showNotification('Pesquisa carregada do link compartilhado', 'info');
                    })
                    .catch(() => {
                        // If category fetch fails, just use the ID
                        const newOption = new Option(categoryId, categoryId, true, true);
                        $('#categorySelect').empty().append(newOption).trigger('change');
                        $('#categoryId').val(categoryId);
                    });
            }
        }

        // ===========================================
        // KEYBOARD SHORTCUTS
        // ===========================================

        function setupKeyboardShortcuts() {
            document.addEventListener('keydown', function(e) {
                // Ctrl+Enter - Submit search
                if (e.ctrlKey && e.key === 'Enter') {
                    e.preventDefault();
                    document.getElementById('researchForm').dispatchEvent(new Event('submit'));
                }

                // Escape - Close loading overlay or filters
                if (e.key === 'Escape') {
                    const overlay = document.getElementById('loadingOverlay');
                    if (overlay && overlay.style.display !== 'none') {
                        // Don't close during search, just minimize visual
                        return;
                    }

                    const filtersPanel = document.getElementById('filtersPanel');
                    if (filtersPanel && filtersPanel.style.display !== 'none') {
                        toggleFilters();
                    }
                }

                // Ctrl+F - Focus on search (category)
                if (e.ctrlKey && e.key === 'f' && !e.shiftKey) {
                    // Only if not in an input
                    if (document.activeElement.tagName !== 'INPUT') {
                        e.preventDefault();
                        $('#categorySelect').select2('open');
                    }
                }

                // Ctrl+S - Toggle favorite
                if (e.ctrlKey && e.key === 's') {
                    e.preventDefault();
                    toggleFavorite();
                }
            });
        }

        // ===========================================
        // NUMBER ANIMATION
        // ===========================================

        function animateNumber(element, targetValue, duration = 1000) {
            if (!element) return;

            const startValue = 0;
            const startTime = performance.now();

            // Parse target value (handle currency and percentages)
            let numericTarget = parseFloat(String(targetValue).replace(/[^0-9.-]/g, ''));
            if (isNaN(numericTarget)) numericTarget = 0;

            const isCurrency = String(targetValue).includes('R$');
            const isPercentage = String(targetValue).includes('%');

            function update(currentTime) {
                const elapsed = currentTime - startTime;
                const progress = Math.min(elapsed / duration, 1);

                // Easing function (ease-out)
                const easeOut = 1 - Math.pow(1 - progress, 3);

                const currentValue = startValue + (numericTarget - startValue) * easeOut;

                if (isCurrency) {
                    element.textContent = formatCurrency(currentValue);
                } else if (isPercentage) {
                    element.textContent = currentValue.toFixed(1) + '%';
                } else {
                    element.textContent = formatNumber(Math.round(currentValue));
                }

                if (progress < 1) {
                    requestAnimationFrame(update);
                }
            }

            requestAnimationFrame(update);
        }

        function animateAllStats() {
            const statsToAnimate = [{
                    id: 'totalListings',
                    isCurrency: false
                },
                {
                    id: 'totalSellers',
                    isCurrency: false
                },
                {
                    id: 'avgPrice',
                    isCurrency: true
                },
                {
                    id: 'catalogRate',
                    isPercentage: true
                },
                {
                    id: 'freeShippingRate',
                    isPercentage: true
                },
                {
                    id: 'opportunitiesCount',
                    isCurrency: false
                }
            ];

            statsToAnimate.forEach(stat => {
                const el = document.getElementById(stat.id);
                if (el) {
                    const target = el.textContent;
                    animateNumber(el, target, 1200);
                }
            });
        }

        // Form submission
        document.getElementById('researchForm').addEventListener('submit', async function(e) {
            e.preventDefault();

            const categoryId = document.getElementById('categoryId').value.trim();
            const brand = document.getElementById('brand').value.trim();
            const maxItems = document.getElementById('maxItems').value;
            const includeSellers = document.getElementById('includeSellers').checked;
            const analyzeShipping = document.getElementById('analyzeShipping').checked;
            const calculateCommissions = document.getElementById('calculateCommissions').checked;

            if (!categoryId || !brand) {
                showNotification('Preencha a categoria e a marca', 'warning');
                return;
            }

            // Get category name for saving
            const categoryName = categoryCache[categoryId] || categoryId;

            // Apply filters before submitting
            applyFilters();

            // Reset and show loading with progress steps
            resetProgressSteps();
            showLoading('Iniciando pesquisa profunda...');
            updateProgressStep(0); // Buscando

            try {
                const params = new URLSearchParams({
                    max_items: maxItems,
                    include_sellers: includeSellers,
                    analyze_shipping: analyzeShipping,
                    calculate_commissions: calculateCommissions
                });

                // Add filter params
                const filterParams = getFilterParams();
                Object.entries(filterParams).forEach(([key, value]) => {
                    params.append(key, value);
                });

                // Step 1: Searching
                await new Promise(r => setTimeout(r, 500));
                updateProgressStep(1); // Filtrando

                const response = await fetch(
                    `/api/research/brand/${categoryId}/${encodeURIComponent(brand)}?${params}`
                );

                // Step 2: Filtering done, analyzing sellers
                updateProgressStep(2); // Sellers

                if (!response.ok) throw new Error('Erro na requisição');

                const result = await response.json();

                // Step 3: Processing
                updateProgressStep(3); // Análise

                if (!result.success) throw new Error(result.error || 'Erro desconhecido');

                // Verificar se há erro no data (token expirado, etc)
                if (result.data.status === 'error' && result.data.error) {
                    throw new Error(result.data.error);
                }

                // Verificar se há resultados
                if (result.data.status === 'no_results') {
                    hideLoading();
                    resetProgressSteps();
                    showNotification('Nenhum anúncio encontrado para esta marca/categoria', 'warning');
                    return;
                }

                // Step 4: Finalizing
                updateProgressStep(4); // Pronto

                // Save to recent searches
                saveRecentSearch(categoryId, categoryName, brand);

                setTimeout(() => {
                    hideLoading();
                    resetProgressSteps();
                    displayResults(result.data);
                    animateAllStats(); // Animate the numbers
                    showNotification('Pesquisa concluída com sucesso!', 'success');
                }, 500);

            } catch (error) {
                hideLoading();
                resetProgressSteps();

                // Verificar se é erro de autenticação ML
                if (error.message.includes('Token') || error.message.includes('expirado') || error.message.includes('reconecte')) {
                    showAuthError(error.message);
                } else if (error.message.includes('indisponível') || error.message.includes('temporariamente') || error.message.includes('API de busca') || error.message.includes('bloqueada') || error.message.includes('proxy')) {
                    showApiUnavailableError(error.message);
                } else {
                    showNotification('Erro na pesquisa: ' + error.message, 'error');
                }
                console.error(error);
            }
        });

        // Mostrar erro de API indisponível
        function showApiUnavailableError(message) {
            const container = document.getElementById('results-section');
            container.style.display = 'block';

            // Extrair informação sobre itens próprios se disponível
            const hasOwnItems = message.includes('anúncios nesta categoria');

            container.innerHTML = `
                <div class="alert alert-info" style="background: rgba(13, 202, 240, 0.1); border: 1px solid var(--info); border-radius: 12px; padding: 30px;">
                    <h4 class="text-info mb-3 text-center">
                        <i class="fas fa-shield-alt me-2"></i>
                        Pesquisa de Mercado Limitada
                    </h4>
                    <p class="text-white-50 mb-3">${message}</p>
                    
                    <div class="row mt-4">
                        <div class="col-md-4 mb-3">
                            <div class="card bg-dark border-secondary h-100">
                                <div class="card-body text-center">
                                    <i class="fas fa-server fa-2x text-warning mb-3"></i>
                                    <h6 class="text-white">Por que isso acontece?</h6>
                                    <p class="text-white-50 small mb-0">
                                        O Mercado Livre bloqueia buscas de servidores de data center para prevenir bots.
                                    </p>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-4 mb-3">
                            <div class="card bg-dark border-success h-100">
                                <div class="card-body text-center">
                                    <i class="fas fa-box fa-2x text-success mb-3"></i>
                                    <h6 class="text-white">Analisar Seus Anúncios</h6>
                                    <p class="text-white-50 small mb-0">
                                        Você pode analisar seus próprios anúncios sem restrições.
                                    </p>
                                    <a href="/items" class="btn btn-success btn-sm mt-2">
                                        <i class="fas fa-arrow-right me-1"></i> Ir para Meus Anúncios
                                    </button>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-4 mb-3">
                            <div class="card bg-dark border-warning h-100">
                                <div class="card-body text-center">
                                    <i class="fas fa-network-wired fa-2x text-warning mb-3"></i>
                                    <h6 class="text-white">Configurar Proxy</h6>
                                    <p class="text-white-50 small mb-0">
                                        Configure um proxy residencial para acessar pesquisas completas.
                                    </p>
                                    <button class="btn btn-secondary btn-sm mt-2" disabled title="Proxies removidos em 2026-08-03 (auditoria TAREFA 1)">
                                        <i class="fas fa-cog me-1"></i> Configurar Proxy
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="d-flex gap-3 justify-content-center flex-wrap mt-4">
                        <a href="https://lista.mercadolivre.com.br/" target="_blank" class="btn btn-outline-info">
                            <i class="fas fa-external-link-alt me-2"></i>
                            Pesquisar no ML
                        </button>
                        <button onclick="location.reload()" class="btn btn-outline-secondary">
                            <i class="fas fa-redo me-2"></i>
                            Tentar Novamente
                        </button>
                    </div>
                </div>
            `;
            container.scrollIntoView({
                behavior: 'smooth'
            });
        }

        // Mostrar erro de autenticação com link para reconectar
        function showAuthError(message) {
            const container = document.getElementById('results-section');
            container.style.display = 'block';
            container.innerHTML = `
                <div class="alert alert-warning" style="background: rgba(255, 193, 7, 0.1); border: 1px solid var(--warning); border-radius: 12px; padding: 30px; text-align: center;">
                    <h4 class="text-warning mb-3">
                        <i class="fas fa-exclamation-triangle me-2"></i>
                        Autenticação Necessária
                    </h4>
                    <p class="text-white-50 mb-4">${message}</p>
                    <a href="/auth/authorize" class="btn btn-warning btn-lg">
                        <i class="fas fa-link me-2"></i>
                        Reconectar Conta do Mercado Livre
                    </button>
                </div>
            `;
            container.scrollIntoView({
                behavior: 'smooth'
            });
        }

        // Compare form
        document.getElementById('compareForm').addEventListener('submit', async function(e) {
            e.preventDefault();

            const categoryId = document.getElementById('compareCategoryId').value.trim();
            const brand1 = document.getElementById('compareBrand1').value.trim();
            const brand2 = document.getElementById('compareBrand2').value.trim();

            if (!categoryId || !brand1 || !brand2) {
                alert('Preencha todos os campos para comparar');
                return;
            }

            showLoading('Comparando marcas...');

            try {
                const response = await fetch(
                    `/api/research/compare/${categoryId}/${encodeURIComponent(brand1)}/${encodeURIComponent(brand2)}`
                );

                if (!response.ok) throw new Error('Erro na requisição');

                const result = await response.json();

                if (!result.success) throw new Error(result.error || 'Erro desconhecido');

                hideLoading();
                displayComparison(result.data);

            } catch (error) {
                hideLoading();
                alert('Erro na comparação: ' + error.message);
            }
        });

        function showLoading(text) {
            document.getElementById('loadingText').textContent = text;
            document.getElementById('loadingOverlay').style.display = 'flex';
        }

        function updateLoadingText(text) {
            document.getElementById('loadingText').textContent = text;
        }

        function hideLoading() {
            document.getElementById('loadingOverlay').style.display = 'none';
        }

        function formatCurrency(value) {
            return new Intl.NumberFormat('pt-BR', {
                style: 'currency',
                currency: 'BRL'
            }).format(value);
        }

        function formatNumber(value) {
            return new Intl.NumberFormat('pt-BR').format(value);
        }

        function displayResults(data) {
            // Store data for export
            lastSearchData = data;

            document.getElementById('results-section').style.display = 'block';
            document.getElementById('comparison-section').style.display = 'none';

            // Summary
            const summary = data.summary || {};
            document.getElementById('totalListings').textContent = formatNumber(summary.total_listings || 0);
            document.getElementById('totalSellers').textContent = formatNumber(summary.total_sellers || 0);
            document.getElementById('avgPrice').textContent = formatCurrency(summary.avg_price || 0);
            document.getElementById('catalogRate').textContent = ((data.listings?.catalog?.percentage) || 0).toFixed(1) + '%';
            document.getElementById('freeShippingRate').textContent = (summary.free_shipping_rate || 0).toFixed(1) + '%';
            document.getElementById('opportunitiesCount').textContent = summary.total_opportunities || 0;

            // Opportunities
            displayOpportunities(data.opportunities || []);

            // Sellers
            displaySellers(data.sellers || {});

            // Pricing
            displayPricing(data.pricing || {});

            // Shipping
            displayShipping(data.shipping || {});

            // Commissions
            displayCommissions(data.commissions || {});

            // Insights
            displayInsights(data.insights || []);

            // Scroll to results
            document.getElementById('results-section').scrollIntoView({
                behavior: 'smooth'
            });
        }

        function displayOpportunities(opportunities) {
            const container = document.getElementById('opportunitiesList');

            if (opportunities.length === 0) {
                container.innerHTML = '<p class="text-white-50">Nenhuma oportunidade identificada</p>';
                return;
            }

            container.innerHTML = opportunities.map(opp => `
                <div class="opportunity-card ${opp.priority}">
                    <div class="d-flex justify-content-between align-items-start mb-2">
                        <h6 class="text-white mb-0">${opp.title}</h6>
                        <span class="priority-badge priority-${opp.priority}">${opp.priority}</span>
                    </div>
                    <p class="text-white-50 mb-2">${opp.description}</p>
                    <small class="text-success">
                        <i class="fas fa-chart-line me-1"></i>
                        ${opp.potential_impact}
                    </small>
                </div>
            `).join('');
        }

        function displaySellers(sellers) {
            // Market concentration
            const concentration = sellers.market_concentration || {};
            const top3 = concentration.top_3_share || 0;
            const top10 = concentration.top_10_share || 0;
            const hhi = concentration.herfindahl_index || 0;

            document.getElementById('top3Share').textContent = top3.toFixed(1) + '%';
            document.getElementById('top10Share').textContent = top10.toFixed(1) + '%';
            document.getElementById('top3Bar').style.width = top3 + '%';
            document.getElementById('top10Bar').style.width = top10 + '%';
            document.getElementById('hhiIndex').textContent = formatNumber(hhi);

            // HHI interpretation
            let hhiText = 'Mercado competitivo';
            if (hhi > 2500) hhiText = 'Alta concentração';
            else if (hhi > 1500) hhiText = 'Concentração moderada';
            document.getElementById('hhiInterpretation').textContent = hhiText;

            // Sellers list
            const sellersList = sellers.sellers || [];
            const container = document.getElementById('sellersList');

            container.innerHTML = sellersList.slice(0, 10).map((seller, index) => {
                const rankClass = index === 0 ? 'gold' : (index === 1 ? 'silver' : (index === 2 ? 'bronze' : ''));
                return `
                    <div class="seller-row">
                        <div class="d-flex align-items-center">
                            <div class="seller-rank ${rankClass} me-3">${index + 1}</div>
                            <div class="flex-grow-1">
                                <div class="text-white fw-bold">${seller.nickname}</div>
                                <small class="text-white-50">
                                    ${seller.total_items} anúncios • ${formatNumber(seller.total_sales)} vendas • 
                                    ${seller.market_share.toFixed(1)}% market share
                                </small>
                            </div>
                            <div class="text-end">
                                <div class="text-success">${formatCurrency(seller.avg_price)}</div>
                                <small class="text-white-50">preço médio</small>
                            </div>
                        </div>
                        <div class="row mt-2 text-center">
                            <div class="col-3">
                                <small class="text-white-50">Catálogo</small>
                                <div class="text-primary fw-bold">${seller.catalog_rate}%</div>
                            </div>
                            <div class="col-3">
                                <small class="text-white-50">Frete Grátis</small>
                                <div class="text-success fw-bold">${seller.free_shipping_rate}%</div>
                            </div>
                            <div class="col-3">
                                <small class="text-white-50">Full</small>
                                <div class="text-warning fw-bold">${seller.full_rate}%</div>
                            </div>
                            <div class="col-3">
                                <small class="text-white-50">Preço Min</small>
                                <div class="text-info fw-bold">${formatCurrency(seller.min_price)}</div>
                            </div>
                        </div>
                    </div>
                `;
            }).join('');
        }

        function displayPricing(pricing) {
            const overall = pricing.overall || {};

            document.getElementById('priceMin').textContent = formatCurrency(overall.min || 0);
            document.getElementById('priceMax').textContent = formatCurrency(overall.max || 0);
            document.getElementById('priceAvg').textContent = formatCurrency(overall.avg || 0);
            document.getElementById('priceMedian').textContent = formatCurrency(overall.median || 0);
            document.getElementById('priceP25').textContent = formatCurrency(overall.p25 || 0);
            document.getElementById('priceP75').textContent = formatCurrency(overall.p75 || 0);

            // Catalog vs Common
            const byType = pricing.by_type || {};
            document.getElementById('catalogAvgPrice').textContent = formatCurrency(byType.catalog?.avg || 0);
            document.getElementById('commonAvgPrice').textContent = formatCurrency(byType.common?.avg || 0);

            const gap = byType.price_gap || {};
            document.getElementById('priceGapInsight').textContent = gap.insight || '';

            // Price distribution chart
            const distribution = pricing.price_distribution || [];

            if (priceDistributionChart) {
                priceDistributionChart.destroy();
            }

            const ctx = document.getElementById('priceDistributionChart').getContext('2d');
            priceDistributionChart = new Chart(ctx, {
                type: 'bar',
                data: {
                    labels: distribution.map(d => d.range),
                    datasets: [{
                        label: 'Quantidade de Anúncios',
                        data: distribution.map(d => d.count),
                        backgroundColor: 'rgba(52, 131, 250, 0.6)',
                        borderColor: 'rgba(52, 131, 250, 1)',
                        borderWidth: 1
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: {
                            display: false
                        }
                    },
                    scales: {
                        y: {
                            beginAtZero: true,
                            ticks: {
                                color: 'rgba(255,255,255,0.7)'
                            },
                            grid: {
                                color: 'rgba(255,255,255,0.1)'
                            }
                        },
                        x: {
                            ticks: {
                                color: 'rgba(255,255,255,0.7)',
                                maxRotation: 45
                            },
                            grid: {
                                display: false
                            }
                        }
                    }
                }
            });
        }

        function displayShipping(shipping) {
            const overview = shipping.overview || {};
            const logistics = shipping.logistics || {};

            // Shipping chart (Free vs Paid)
            if (shippingChart) shippingChart.destroy();

            const ctx1 = document.getElementById('shippingChart').getContext('2d');
            shippingChart = new Chart(ctx1, {
                type: 'doughnut',
                data: {
                    labels: ['Frete Grátis', 'Frete Pago'],
                    datasets: [{
                        data: [
                            overview.free_shipping?.count || 0,
                            overview.paid_shipping?.count || 0
                        ],
                        backgroundColor: ['#00A650', '#F23D4F'],
                        borderWidth: 0
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: {
                            position: 'bottom',
                            labels: {
                                color: 'rgba(255,255,255,0.7)'
                            }
                        }
                    }
                }
            });

            // Logistics chart
            if (logisticsChart) logisticsChart.destroy();

            const ctx2 = document.getElementById('logisticsChart').getContext('2d');
            logisticsChart = new Chart(ctx2, {
                type: 'doughnut',
                data: {
                    labels: ['Full', 'Flex', 'Drop Off', 'Outros'],
                    datasets: [{
                        data: [
                            logistics.full?.count || 0,
                            logistics.flex?.count || 0,
                            logistics.drop_off?.count || 0,
                            logistics.other?.count || 0
                        ],
                        backgroundColor: ['#FFE600', '#3483FA', '#FF7733', '#666666'],
                        borderWidth: 0
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: {
                            position: 'bottom',
                            labels: {
                                color: 'rgba(255,255,255,0.7)'
                            }
                        }
                    }
                }
            });

            // Shipping insights
            const insights = shipping.insights || [];
            const container = document.getElementById('shippingInsights');

            if (insights.length === 0) {
                container.innerHTML = '<p class="text-white-50">Sem insights de frete</p>';
            } else {
                container.innerHTML = insights.map(insight => `
                    <div class="insight-item">
                        <span class="insight-category">${insight.type}</span>
                        <p class="text-white mb-0 mt-1">${insight.message}</p>
                    </div>
                `).join('');
            }
        }

        function displayCommissions(commissions) {
            const summary = commissions.summary || {};

            document.getElementById('totalRevenue').textContent = formatCurrency(summary.total_revenue || 0);
            document.getElementById('totalCommission').textContent = formatCurrency(summary.total_ml_commission || 0);
            document.getElementById('totalPaymentFee').textContent = formatCurrency(summary.total_payment_fee || 0);
            document.getElementById('totalFees').textContent = formatCurrency(summary.total_fees || 0);
            document.getElementById('effectiveRate').textContent = (summary.effective_rate || 0).toFixed(2) + '%';

            // By listing type chart
            const byType = commissions.by_listing_type || {};

            if (commissionsChart) commissionsChart.destroy();

            const labels = Object.keys(byType);
            const data = labels.map(l => byType[l].count || 0);

            const ctx = document.getElementById('commissionsChart').getContext('2d');
            commissionsChart = new Chart(ctx, {
                type: 'pie',
                data: {
                    labels: labels.map(l => l.replace('_', ' ').toUpperCase()),
                    datasets: [{
                        data: data,
                        backgroundColor: [
                            '#FFE600', '#3483FA', '#00A650', '#FF7733', '#F23D4F', '#9B59B6'
                        ],
                        borderWidth: 0
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: {
                            position: 'bottom',
                            labels: {
                                color: 'rgba(255,255,255,0.7)'
                            }
                        }
                    }
                }
            });

            // Commission rates table
            const rates = commissions.commission_rates_reference || {};
            const tableBody = document.getElementById('commissionRatesTable');

            tableBody.innerHTML = Object.entries(rates).map(([type, rate]) => `
                <tr>
                    <td>${type.replace('_', ' ').toUpperCase()}</td>
                    <td>${rate}%</td>
                    <td>${byType[type]?.count || 0}</td>
                </tr>
            `).join('');
        }

        function displayInsights(insights) {
            const container = document.getElementById('insightsList');

            if (insights.length === 0) {
                container.innerHTML = '<p class="text-white-50">Nenhum insight disponível</p>';
                return;
            }

            container.innerHTML = insights.map(insight => `
                <div class="insight-item">
                    <span class="insight-category">${insight.category}</span>
                    <p class="text-white mb-2 mt-2">${insight.insight}</p>
                    <div class="alert alert-warning py-2 mb-0">
                        <i class="fas fa-lightbulb me-2"></i>
                        <strong>Recomendação:</strong> ${insight.recommendation}
                    </div>
                </div>
            `).join('');
        }

        function displayComparison(data) {
            document.getElementById('comparison-section').style.display = 'block';
            document.getElementById('results-section').style.display = 'none';

            const brand1 = data.brand_1 || {};
            const brand2 = data.brand_2 || {};
            const analysis = data.analysis || {};

            document.getElementById('brand1Name').textContent = brand1.name || 'Marca 1';
            document.getElementById('brand2Name').textContent = brand2.name || 'Marca 2';

            const s1 = brand1.summary || {};
            const s2 = brand2.summary || {};

            document.getElementById('brand1Stats').innerHTML = `
                <div class="my-3">
                    <div class="h2 text-primary">${formatNumber(s1.total_listings || 0)}</div>
                    <small class="text-muted">Anúncios</small>
                </div>
                <div class="my-3">
                    <div class="h3 text-success">${formatCurrency(s1.avg_price || 0)}</div>
                    <small class="text-muted">Preço Médio</small>
                </div>
                <div class="my-3">
                    <div class="h4">${formatNumber(s1.total_sellers || 0)}</div>
                    <small class="text-muted">Sellers</small>
                </div>
                <div class="my-3">
                    <div class="h4">${(s1.free_shipping_rate || 0).toFixed(1)}%</div>
                    <small class="text-muted">Frete Grátis</small>
                </div>
            `;

            document.getElementById('brand2Stats').innerHTML = `
                <div class="my-3">
                    <div class="h2 text-primary">${formatNumber(s2.total_listings || 0)}</div>
                    <small class="text-muted">Anúncios</small>
                </div>
                <div class="my-3">
                    <div class="h3 text-success">${formatCurrency(s2.avg_price || 0)}</div>
                    <small class="text-muted">Preço Médio</small>
                </div>
                <div class="my-3">
                    <div class="h4">${formatNumber(s2.total_sellers || 0)}</div>
                    <small class="text-muted">Sellers</small>
                </div>
                <div class="my-3">
                    <div class="h4">${(s2.free_shipping_rate || 0).toFixed(1)}%</div>
                    <small class="text-muted">Frete Grátis</small>
                </div>
            `;

            // Scroll to comparison
            document.getElementById('comparison-section').scrollIntoView({
                behavior: 'smooth'
            });
        }
    </script>