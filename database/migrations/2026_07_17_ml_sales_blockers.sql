-- Sales blockers queue (PLAN-IRR-001 / ADR-003) — snapshots sem tokens
CREATE TABLE IF NOT EXISTS `ml_sales_blockers` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `account_id` int NOT NULL,
  `item_id` varchar(32) NOT NULL,
  `queue` enum('urgent','exposure','account') NOT NULL DEFAULT 'urgent',
  `source_status` varchar(64) NOT NULL DEFAULT 'unknown',
  `severity` varchar(32) NOT NULL DEFAULT 'high',
  `reason` text NULL,
  `remedy` text NULL,
  `wordings_json` json DEFAULT NULL,
  `performance_json` json DEFAULT NULL,
  `scanned_at` datetime NOT NULL,
  `updated_at` datetime NOT NULL,
  `resolved_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_account_item_queue` (`account_id`,`item_id`,`queue`),
  KEY `idx_account_queue_open` (`account_id`,`queue`,`resolved_at`),
  KEY `idx_scanned_at` (`scanned_at`),
  CONSTRAINT `fk_ml_sales_blockers_account`
    FOREIGN KEY (`account_id`) REFERENCES `ml_accounts` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- DOWN:
-- DROP TABLE IF EXISTS `ml_sales_blockers`;
