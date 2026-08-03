-- Sentinela: estado atual e série diária por risco (read-only)
CREATE TABLE IF NOT EXISTS `sentinela_risk_state` (
  `account_id` int unsigned NOT NULL,
  `risk_key` varchar(64) NOT NULL,
  `label` varchar(128) NOT NULL,
  `value_num` decimal(18,6) DEFAULT NULL,
  `value_text` varchar(255) DEFAULT NULL,
  `limit_num` decimal(18,6) DEFAULT NULL,
  `pct_of_limit` decimal(10,4) DEFAULT NULL,
  `status` enum('verde','amarelo','vermelho','nd') NOT NULL DEFAULT 'nd',
  `reason` varchar(512) DEFAULT NULL,
  `source` varchar(64) NOT NULL DEFAULT 'unknown',
  `meta` json DEFAULT NULL,
  `collected_at` datetime NOT NULL,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`account_id`, `risk_key`),
  KEY `idx_sentinela_status` (`account_id`, `status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `sentinela_risk_daily` (
  `account_id` int unsigned NOT NULL,
  `risk_key` varchar(64) NOT NULL,
  `date` date NOT NULL,
  `value_num` decimal(18,6) DEFAULT NULL,
  `pct_of_limit` decimal(10,4) DEFAULT NULL,
  `status` enum('verde','amarelo','vermelho','nd') NOT NULL DEFAULT 'nd',
  PRIMARY KEY (`account_id`, `risk_key`, `date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
