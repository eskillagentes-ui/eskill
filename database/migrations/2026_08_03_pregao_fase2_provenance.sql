-- Pregão Fase 2: provenance de métricas + source nos eventos
ALTER TABLE `pregao_events`
  ADD COLUMN IF NOT EXISTS `source` varchar(32) NOT NULL DEFAULT 'live' AFTER `payload`;

ALTER TABLE `account_index_metrics`
  ADD COLUMN IF NOT EXISTS `metrics_meta` json DEFAULT NULL AFTER `semaforo_status`,
  ADD COLUMN IF NOT EXISTS `factors_active` tinyint unsigned DEFAULT NULL AFTER `metrics_meta`,
  ADD COLUMN IF NOT EXISTS `factors_total` tinyint unsigned NOT NULL DEFAULT 5 AFTER `factors_active`;

-- MySQL < 8.0.12 pode não ter IF NOT EXISTS em ADD COLUMN — fallback abaixo via procedure-less checks
-- Aplicar com bin/pregao-migrate-fase2.php se necessário.
