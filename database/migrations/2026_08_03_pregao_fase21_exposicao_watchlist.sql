-- Pregão Fase 2.1: exposição (visitas) + watchlist concorrentes
ALTER TABLE `account_index_metrics`
  ADD COLUMN IF NOT EXISTS `visitas_7d` decimal(14,2) NOT NULL DEFAULT 0.00 AFTER `posicao_media`;

ALTER TABLE `account_index_baselines`
  ADD COLUMN IF NOT EXISTS `visitas_baseline` decimal(14,2) NOT NULL DEFAULT 1.00 AFTER `pos_baseline`;

-- Extende competitor_items (já existente / vazia) com campos do watchlist Pregão
ALTER TABLE `competitor_items`
  ADD COLUMN IF NOT EXISTS `apelido` varchar(128) NOT NULL DEFAULT '' AFTER `title`,
  ADD COLUMN IF NOT EXISTS `keyword_alvo` varchar(255) DEFAULT NULL AFTER `apelido`,
  ADD COLUMN IF NOT EXISTS `sold_quantity` int DEFAULT NULL AFTER `price`,
  ADD COLUMN IF NOT EXISTS `available_quantity` int DEFAULT NULL AFTER `sold_quantity`,
  ADD COLUMN IF NOT EXISTS `last_sold_delta` int NOT NULL DEFAULT 0 AFTER `available_quantity`,
  ADD COLUMN IF NOT EXISTS `active` tinyint(1) NOT NULL DEFAULT 1 AFTER `status`,
  ADD COLUMN IF NOT EXISTS `last_checked_at` datetime DEFAULT NULL AFTER `updated_at`;

CREATE TABLE IF NOT EXISTS `competitor_item_snapshots` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `account_id` int NOT NULL,
  `competitor_item_id` int NOT NULL,
  `mlb_id` varchar(32) NOT NULL,
  `price` decimal(14,2) DEFAULT NULL,
  `sold_quantity` int DEFAULT NULL,
  `available_quantity` int DEFAULT NULL,
  `status` varchar(32) DEFAULT NULL,
  `captured_at` datetime(3) NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_cis_item_ts` (`competitor_item_id`, `captured_at`),
  KEY `idx_cis_account_ts` (`account_id`, `captured_at`),
  KEY `idx_cis_mlb` (`mlb_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
