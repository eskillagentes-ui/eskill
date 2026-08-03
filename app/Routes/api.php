<?php

declare(strict_types=1);

/**
 * API Route Loader — Fase 8 refactoring
 *
 * Este arquivo é um thin-loader que delega para sub-módulos de domínio.
 * Todos os sub-arquivos herdam o $router via escopo PHP require.
 *
 * @var \App\Router $router
 */

// ================================================================
// Domain route sub-files — app/Routes/api/
// ================================================================

// Auth, Dashboard, User, Onboarding, Render, Settings
require __DIR__ . '/api/auth.php';

// Pregão (read-only realtime)
require __DIR__ . '/api/pregao.php';

// Integrations: Brevo, Clawdbot, Assistant Connector, OpenClaw
require __DIR__ . '/api/integrations.php';

// SEO: SEO Killer, ML-AI Pipeline, Market Data, TechSheet, Quality, Shipping,
//      ListingBuilder, TitleGenerator, AIML Services, SEO Intelligence
require __DIR__ . '/api/seo.php';

// Catalog Clone: FASE 1-12, Notifications, AB Testing, Automation,
//    Seller Recommendations, ROI Analysis, SEO Integration, Compliance,
//    Analytics, Management, Sync, Advanced, Scheduler, Event Triggers, Charts
require __DIR__ . '/api/clone.php';

// Items, Categories, Search, Orders, Analytics, Reports, Security,
//    Performance, Export, EAN, Brand, Push, Health, Notifications, etc.
require __DIR__ . '/api/items.php';

// Pricing Intelligence (FASE 1-3), Dynamic Pricing, AI Predictions, Chatbot
require __DIR__ . '/api/pricing.php';

// AI Optimization, Error Monitoring, AI Insights, Competitor Monitor,
//    SEO API, Token Management, Automation, Monitoring, Stock Sync
require __DIR__ . '/api/ai.php';
