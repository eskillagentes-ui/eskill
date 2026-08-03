-- Bloco 5 — Módulo Ads (read-only): custos por SKU, métricas diárias, marco zero, auditoria do índice

CREATE TABLE IF NOT EXISTS `sku_custos` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `account_id` int NOT NULL,
  `mlb_id` varchar(32) NOT NULL,
  `custo_produto` decimal(12,4) NOT NULL,
  `comissao_pct` decimal(8,4) NOT NULL DEFAULT 0.0000,
  `frete_medio` decimal(12,4) NOT NULL DEFAULT 0.0000,
  `custos_operacionais_pct` decimal(8,4) NOT NULL DEFAULT 0.0000,
  `preco_minimo` decimal(12,4) NOT NULL,
  `margem_bruta_pct` decimal(8,4) DEFAULT NULL,
  `margem_liquida_pct` decimal(8,4) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_sku_custos_account_mlb` (`account_id`, `mlb_id`),
  KEY `idx_sku_custos_mlb` (`mlb_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `ads_campaign_metrics_daily` (
  `account_id` int NOT NULL,
  `campaign_id` varchar(100) NOT NULL,
  `date` date NOT NULL,
  `status` varchar(50) DEFAULT NULL,
  `orcamento_diario` decimal(12,2) DEFAULT NULL,
  `roas_objetivo` decimal(12,4) DEFAULT NULL,
  `gasto` decimal(12,2) NOT NULL DEFAULT 0.00,
  `impressoes` int NOT NULL DEFAULT 0,
  `cliques` int NOT NULL DEFAULT 0,
  `cpc_medio` decimal(12,4) DEFAULT NULL,
  `vendas_atribuidas` int NOT NULL DEFAULT 0,
  `receita_atribuida` decimal(12,2) NOT NULL DEFAULT 0.00,
  `acos` decimal(12,4) DEFAULT NULL,
  `roas_real` decimal(12,4) DEFAULT NULL,
  `data` json DEFAULT NULL,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`account_id`, `campaign_id`, `date`),
  KEY `idx_ads_camp_daily_date` (`account_id`, `date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `ads_sku_metrics_daily` (
  `account_id` int NOT NULL,
  `campaign_id` varchar(100) NOT NULL,
  `mlb_id` varchar(32) NOT NULL,
  `date` date NOT NULL,
  `gasto` decimal(12,2) NOT NULL DEFAULT 0.00,
  `impressoes` int NOT NULL DEFAULT 0,
  `cliques` int NOT NULL DEFAULT 0,
  `cpc_medio` decimal(12,4) DEFAULT NULL,
  `vendas_atribuidas` int NOT NULL DEFAULT 0,
  `receita_atribuida` decimal(12,2) NOT NULL DEFAULT 0.00,
  `acos` decimal(12,4) DEFAULT NULL,
  `roas_real` decimal(12,4) DEFAULT NULL,
  `roas_objetivo` decimal(12,4) DEFAULT NULL,
  `health` decimal(6,4) DEFAULT NULL,
  `data` json DEFAULT NULL,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`account_id`, `campaign_id`, `mlb_id`, `date`),
  KEY `idx_ads_sku_daily_mlb` (`account_id`, `mlb_id`, `date`),
  KEY `idx_ads_sku_daily_date` (`account_id`, `date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `ads_account_metrics_daily` (
  `account_id` int NOT NULL,
  `date` date NOT NULL,
  `gasto` decimal(12,2) NOT NULL DEFAULT 0.00,
  `receita_atribuida` decimal(12,2) NOT NULL DEFAULT 0.00,
  `receita_total` decimal(12,2) NOT NULL DEFAULT 0.00,
  `acos` decimal(12,4) DEFAULT NULL,
  `tacos` decimal(12,4) DEFAULT NULL,
  `campanhas_ativas` int NOT NULL DEFAULT 0,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`account_id`, `date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `ads_recovery_milestones` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `account_id` int NOT NULL,
  `mlb_id` varchar(32) NOT NULL,
  `predecessor_mlb_id` varchar(32) DEFAULT NULL,
  `promo_activated_at` datetime DEFAULT NULL,
  `campaign_activated_at` datetime DEFAULT NULL,
  `notes` text,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_ads_recovery_account_mlb` (`account_id`, `mlb_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `account_index_audit` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `account_id` int NOT NULL,
  `event_type` varchar(64) NOT NULL,
  `before_json` json DEFAULT NULL,
  `after_json` json DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_account_index_audit_account` (`account_id`, `created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
