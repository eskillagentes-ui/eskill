-- =============================================================================
-- Snapshot de schema (somente estrutura, sem dados) para bootstrap do banco
-- de teste no CI/E2E. Gerado via mysqldump --no-data a partir do banco de
-- desenvolvimento já totalmente migrado.
--
-- Por quê isso existe: as ~122 migrations em database/migrations/ foram
-- pensadas para aplicação INCREMENTAL num banco que evolui ao longo do tempo,
-- não para replay do zero. Ao rodar bin/migrate.php contra um banco vazio
-- (exatamente o cenário de CI), a ordenação alfabética de arquivos expõe
-- referências a colunas/tabelas que só existem em migrations posteriores,
-- travando o runner. Ver histórico de 026_performance_optimization_indexes.sql
-- para um exemplo corrigido.
--
-- Uso no CI (ver .github/workflows/playwright.yml):
--   1. mysql < database/ci/schema.sql          (estrutura completa)
--   2. mysql < database/ci/migrations_seed.sql (marca migrations já refletidas aqui como aplicadas)
--   3. php bin/migrate.php --testing           (aplica só migrations genuinamente novas)
--
-- Para regenerar após criar novas migrations (rodar num ambiente com o
-- schema já atualizado e todas as migrations aplicadas):
--   mysqldump --no-data --routines --triggers --skip-comments \
--     --column-statistics=0 --set-gtid-purged=OFF --no-tablespaces \
--     --skip-lock-tables --single-transaction <db> \
--     | sed -E 's/DEFINER=`[^`]*`@`[^`]*` //g; s/\/\*![0-9]+ DEFINER=`[^`]*`@`[^`]*`\*\///g' \
--     > database/ci/schema.sql
--   mysqldump --no-create-info --skip-triggers --complete-insert --no-tablespaces \
--     --skip-comments --column-statistics=0 --set-gtid-purged=OFF \
--     --skip-lock-tables --single-transaction <db> migrations \
--     > database/ci/migrations_seed.sql
-- =============================================================================

/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!50503 SET NAMES utf8mb4 */;
/*!40103 SET @OLD_TIME_ZONE=@@TIME_ZONE */;
/*!40103 SET TIME_ZONE='+00:00' */;
/*!40014 SET @OLD_UNIQUE_CHECKS=@@UNIQUE_CHECKS, UNIQUE_CHECKS=0 */;
/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;
/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;
/*!40111 SET @OLD_SQL_NOTES=@@SQL_NOTES, SQL_NOTES=0 */;
/*!50717 SELECT COUNT(*) INTO @rocksdb_has_p_s_session_variables FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = 'performance_schema' AND TABLE_NAME = 'session_variables' */;
/*!50717 SET @rocksdb_get_is_supported = IF (@rocksdb_has_p_s_session_variables, 'SELECT COUNT(*) INTO @rocksdb_is_supported FROM performance_schema.session_variables WHERE VARIABLE_NAME=\'rocksdb_bulk_load\'', 'SELECT 0') */;
/*!50717 PREPARE s FROM @rocksdb_get_is_supported */;
/*!50717 EXECUTE s */;
/*!50717 DEALLOCATE PREPARE s */;
/*!50717 SET @rocksdb_enable_bulk_load = IF (@rocksdb_is_supported, 'SET SESSION rocksdb_bulk_load = 1', 'SET @rocksdb_dummy_bulk_load = 0') */;
/*!50717 PREPARE s FROM @rocksdb_enable_bulk_load */;
/*!50717 EXECUTE s */;
/*!50717 DEALLOCATE PREPARE s */;
DROP TABLE IF EXISTS `account_health_history`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `account_health_history` (
  `id` int NOT NULL AUTO_INCREMENT,
  `account_id` int NOT NULL,
  `overall_score` int NOT NULL DEFAULT '0',
  `reputation_score` int NOT NULL DEFAULT '0',
  `seo_score` int NOT NULL DEFAULT '0',
  `competitiveness_score` int NOT NULL DEFAULT '0',
  `operation_score` int NOT NULL DEFAULT '0',
  `sales_score` int NOT NULL DEFAULT '0',
  `action_count` int NOT NULL DEFAULT '0',
  `critical_count` int NOT NULL DEFAULT '0',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_account_date` (`account_id`,`created_at`),
  KEY `idx_account_id` (`account_id`)
) ENGINE=InnoDB AUTO_INCREMENT=83 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `account_xray_reports`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `account_xray_reports` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `account_id` int NOT NULL,
  `seller_id` varchar(50) DEFAULT NULL,
  `nickname` varchar(100) DEFAULT NULL,
  `status` enum('pending','running','completed','failed') NOT NULL DEFAULT 'pending',
  `score_overall` tinyint unsigned DEFAULT NULL COMMENT '0-100 overall health score',
  `account_status` varchar(30) DEFAULT NULL COMMENT 'TRAVADA|PENALIZADA|EM_RECUPERACAO|ESTAVEL|FORTE',
  `items_total` smallint unsigned NOT NULL DEFAULT '0',
  `items_analyzed` smallint unsigned NOT NULL DEFAULT '0',
  `critical_issues` tinyint unsigned NOT NULL DEFAULT '0',
  `report_json` longtext COMMENT 'Full X-Ray report (JSON)',
  `options_json` text COMMENT 'Options used for this run',
  `error_message` text,
  `started_at` datetime DEFAULT NULL,
  `completed_at` datetime DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_account_id` (`account_id`),
  KEY `idx_status` (`status`),
  KEY `idx_created_at` (`created_at`)
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci COMMENT='Raio X — Account diagnostic reports';
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `active_optimizations`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `active_optimizations` (
  `id` int NOT NULL AUTO_INCREMENT,
  `account_id` varchar(100) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `item_id` varchar(100) COLLATE utf8mb4_general_ci NOT NULL,
  `optimization_type` varchar(50) COLLATE utf8mb4_general_ci NOT NULL,
  `started_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `estimated_completion` timestamp NULL DEFAULT NULL,
  `current_status` enum('running','monitoring','completed','failed') COLLATE utf8mb4_general_ci DEFAULT 'running',
  `progress_percentage` decimal(5,2) DEFAULT '0.00',
  `estimated_impact` decimal(10,2) DEFAULT '0.00',
  `actual_impact` decimal(10,2) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_account_item` (`account_id`,`item_id`),
  KEY `idx_status` (`current_status`),
  KEY `idx_started_at` (`started_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `activity_logs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `activity_logs` (
  `id` int NOT NULL AUTO_INCREMENT,
  `user_id` int DEFAULT NULL,
  `action` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `description` text COLLATE utf8mb4_unicode_ci,
  `ip_address` varchar(45) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `user_agent` text COLLATE utf8mb4_unicode_ci,
  `metadata` json DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_user_id` (`user_id`),
  KEY `idx_action` (`action`),
  KEY `idx_created_at` (`created_at`),
  CONSTRAINT `activity_logs_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=13 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `adaptation_rules`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `adaptation_rules` (
  `id` int NOT NULL AUTO_INCREMENT,
  `account_id` varchar(100) COLLATE utf8mb4_general_ci NOT NULL,
  `rules_data` json NOT NULL,
  `version` int DEFAULT '1',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `account_id` (`account_id`),
  KEY `idx_account_id` (`account_id`),
  KEY `idx_version` (`version`)
) ENGINE=InnoDB AUTO_INCREMENT=5 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `ads_campaigns_cache`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `ads_campaigns_cache` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `account_id` int unsigned NOT NULL,
  `campaign_id` varchar(100) NOT NULL,
  `name` varchar(255) DEFAULT NULL,
  `status` varchar(50) DEFAULT 'unknown',
  `daily_budget` decimal(10,2) DEFAULT '0.00',
  `type` varchar(50) DEFAULT 'product_ad',
  `data` json DEFAULT NULL,
  `updated_at` datetime DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_account_campaign` (`account_id`,`campaign_id`),
  KEY `idx_account_status` (`account_id`,`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `ads_metrics_history`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `ads_metrics_history` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `account_id` int unsigned NOT NULL,
  `campaign_id` varchar(100) NOT NULL,
  `date` date NOT NULL,
  `cost` decimal(12,2) DEFAULT '0.00',
  `revenue` decimal(12,2) DEFAULT '0.00',
  `clicks` int DEFAULT '0',
  `impressions` int DEFAULT '0',
  `conversions` int DEFAULT '0',
  `data` json DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_account_campaign_date` (`account_id`,`campaign_id`,`date`),
  KEY `idx_account_date` (`account_id`,`date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `advanced_performance_metrics`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `advanced_performance_metrics` (
  `id` int NOT NULL AUTO_INCREMENT,
  `account_id` varchar(100) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `item_id` varchar(100) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `metric_date` date NOT NULL,
  `hour_of_day` tinyint DEFAULT NULL,
  `day_of_week` tinyint DEFAULT NULL,
  `week_of_year` smallint DEFAULT NULL,
  `month` tinyint DEFAULT NULL,
  `quarter` tinyint DEFAULT NULL,
  `year` smallint DEFAULT NULL,
  `is_holiday` tinyint(1) DEFAULT '0',
  `is_weekend` tinyint(1) DEFAULT '0',
  `seasonal_factor` decimal(5,4) DEFAULT NULL,
  `impressions` bigint DEFAULT '0',
  `clicks` bigint DEFAULT '0',
  `views` bigint DEFAULT '0',
  `unique_visitors` bigint DEFAULT '0',
  `click_through_rate` decimal(5,4) DEFAULT NULL,
  `engagement_rate` decimal(5,4) DEFAULT NULL,
  `conversions` int DEFAULT '0',
  `revenue` decimal(12,2) DEFAULT '0.00',
  `profit` decimal(12,2) DEFAULT '0.00',
  `conversion_rate` decimal(5,4) DEFAULT NULL,
  `average_order_value` decimal(10,2) DEFAULT NULL,
  `competitor_ranking` int DEFAULT NULL,
  `market_share` decimal(5,4) DEFAULT NULL,
  `price_competitiveness` decimal(5,4) DEFAULT NULL,
  `visibility_score` decimal(5,4) DEFAULT NULL,
  `seo_score` decimal(5,4) DEFAULT NULL,
  `optimization_count` int DEFAULT '0',
  `last_optimization` timestamp NULL DEFAULT NULL,
  `optimization_impact` decimal(5,4) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_daily_metrics` (`account_id`,`item_id`,`metric_date`),
  KEY `idx_date_range` (`metric_date`,`year`,`quarter`,`month`),
  KEY `idx_performance` (`conversion_rate`,`revenue`,`seo_score`),
  KEY `idx_time_patterns` (`hour_of_day`,`day_of_week`,`is_weekend`,`is_holiday`),
  KEY `idx_advanced_metrics_composite` (`account_id`,`metric_date`,`seo_score`,`conversion_rate`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci ROW_FORMAT=COMPRESSED KEY_BLOCK_SIZE=8;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `agent_features`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `agent_features` (
  `id` int NOT NULL AUTO_INCREMENT,
  `project_id` int NOT NULL,
  `feature_id` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `category` enum('functional','ui','performance','security','other') COLLATE utf8mb4_unicode_ci NOT NULL,
  `description` text COLLATE utf8mb4_unicode_ci NOT NULL,
  `steps` json NOT NULL,
  `passes` tinyint(1) DEFAULT '0',
  `priority` enum('high','medium','low') COLLATE utf8mb4_unicode_ci DEFAULT 'medium',
  `tested_at` timestamp NULL DEFAULT NULL,
  `test_results` json DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_feature` (`project_id`,`feature_id`),
  KEY `idx_priority` (`priority`),
  KEY `idx_passes` (`passes`),
  CONSTRAINT `agent_features_ibfk_1` FOREIGN KEY (`project_id`) REFERENCES `agent_projects` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=60 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `agent_progress_log`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `agent_progress_log` (
  `id` int NOT NULL AUTO_INCREMENT,
  `project_id` int NOT NULL,
  `session_type` enum('initializer','coding','testing','cleanup') COLLATE utf8mb4_unicode_ci NOT NULL,
  `feature_id` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `status` enum('completed','in_progress','failed') COLLATE utf8mb4_unicode_ci NOT NULL,
  `summary` text COLLATE utf8mb4_unicode_ci,
  `data` json DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_project` (`project_id`),
  KEY `idx_type` (`session_type`),
  KEY `idx_created` (`created_at`),
  CONSTRAINT `agent_progress_log_ibfk_1` FOREIGN KEY (`project_id`) REFERENCES `agent_projects` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=54 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `agent_projects`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `agent_projects` (
  `id` int NOT NULL AUTO_INCREMENT,
  `name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `description` text COLLATE utf8mb4_unicode_ci,
  `category` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `requirements` json DEFAULT NULL,
  `status` enum('not_initialized','initialized','in_progress','completed','paused') COLLATE utf8mb4_unicode_ci DEFAULT 'not_initialized',
  `completion_percentage` decimal(5,2) DEFAULT '0.00',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_status` (`status`),
  KEY `idx_category` (`category`)
) ENGINE=InnoDB AUTO_INCREMENT=6 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `ai_ab_test_metrics`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `ai_ab_test_metrics` (
  `id` int NOT NULL AUTO_INCREMENT,
  `test_id` int NOT NULL,
  `variant` varchar(1) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'a or b',
  `date` date NOT NULL,
  `views` int DEFAULT '0',
  `visits` int DEFAULT '0',
  `sales` int DEFAULT '0',
  `revenue` decimal(10,2) DEFAULT '0.00',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_test_variant_date` (`test_id`,`variant`,`date`),
  KEY `idx_test_id` (`test_id`),
  KEY `idx_date` (`date`),
  CONSTRAINT `ai_ab_test_metrics_ibfk_1` FOREIGN KEY (`test_id`) REFERENCES `ai_ab_tests` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=5 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='A/B test metrics tracking';
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `ai_ab_tests`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `ai_ab_tests` (
  `id` int NOT NULL AUTO_INCREMENT,
  `test_name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `item_id` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
  `variant_a` json NOT NULL COMMENT 'Original/Control variant',
  `variant_b` json NOT NULL COMMENT 'Optimized variant',
  `status` enum('active','paused','completed') COLLATE utf8mb4_unicode_ci DEFAULT 'active',
  `winner` varchar(1) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'a or b',
  `confidence_level` int DEFAULT '95',
  `is_significant` tinyint(1) DEFAULT '0',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `started_at` timestamp NULL DEFAULT NULL,
  `ended_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_item_id` (`item_id`),
  KEY `idx_status` (`status`),
  KEY `idx_created_at` (`created_at`)
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='A/B test definitions';
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `ai_agent_logs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `ai_agent_logs` (
  `id` int NOT NULL AUTO_INCREMENT,
  `agent_code` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
  `level` enum('info','warning','error','action') COLLATE utf8mb4_unicode_ci DEFAULT 'info',
  `message` text COLLATE utf8mb4_unicode_ci NOT NULL,
  `context` json DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `agent_code` (`agent_code`),
  KEY `level` (`level`),
  KEY `created_at` (`created_at`)
) ENGINE=InnoDB AUTO_INCREMENT=19 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `ai_agents`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `ai_agents` (
  `id` int NOT NULL AUTO_INCREMENT,
  `code` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
  `name` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `description` text COLLATE utf8mb4_unicode_ci,
  `status` enum('active','paused','error') COLLATE utf8mb4_unicode_ci DEFAULT 'active',
  `config` json DEFAULT NULL,
  `last_run_at` datetime DEFAULT NULL,
  `next_run_at` datetime DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `code` (`code`)
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `ai_audit_log`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `ai_audit_log` (
  `id` int NOT NULL AUTO_INCREMENT,
  `item_id` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
  `action` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'optimize, rollback, apply, etc',
  `changes` json NOT NULL COMMENT 'Detailed changes made',
  `metadata` json DEFAULT NULL COMMENT 'Additional context',
  `before_state` json DEFAULT NULL COMMENT 'Complete state before change',
  `after_state` json DEFAULT NULL COMMENT 'Complete state after change',
  `score_before` int DEFAULT NULL,
  `score_after` int DEFAULT NULL,
  `cost` decimal(10,4) DEFAULT NULL,
  `ai_provider` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `ai_model` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `user_id` int DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_item_id` (`item_id`),
  KEY `idx_action` (`action`),
  KEY `idx_created_at` (`created_at`),
  KEY `idx_user_id` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Audit log for all AI optimization actions';
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `ai_auto_optimization_rules`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `ai_auto_optimization_rules` (
  `id` int NOT NULL AUTO_INCREMENT,
  `account_id` int DEFAULT NULL,
  `name` varchar(255) NOT NULL,
  `condition_type` enum('score','age','performance','category') NOT NULL,
  `condition_operator` enum('lt','lte','eq','gte','gt') NOT NULL,
  `condition_value` varchar(255) NOT NULL,
  `action` enum('auto_optimize','suggest','monitor','alert') NOT NULL,
  `priority` int DEFAULT '0',
  `is_active` tinyint(1) DEFAULT '1',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_account` (`account_id`),
  KEY `idx_active` (`is_active`)
) ENGINE=InnoDB AUTO_INCREMENT=8 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `ai_decisions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `ai_decisions` (
  `id` varchar(50) COLLATE utf8mb4_general_ci NOT NULL,
  `target_id` varchar(50) COLLATE utf8mb4_general_ci NOT NULL,
  `decision_type` varchar(20) COLLATE utf8mb4_general_ci NOT NULL,
  `decision_data` json NOT NULL,
  `factors` json NOT NULL,
  `ml_prediction` json NOT NULL,
  `confidence` decimal(5,3) NOT NULL,
  `execution_time_ms` int DEFAULT '0',
  `applied` tinyint(1) DEFAULT '0',
  `applied_at` datetime DEFAULT NULL,
  `apply_result` json DEFAULT NULL,
  `created_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_target_id` (`target_id`),
  KEY `idx_decision_type` (`decision_type`),
  KEY `idx_confidence` (`confidence`),
  KEY `idx_created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `ai_feedback`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `ai_feedback` (
  `id` int NOT NULL AUTO_INCREMENT,
  `account_id` int DEFAULT NULL,
  `item_id` varchar(50) NOT NULL,
  `optimization_id` int DEFAULT NULL,
  `feedback_type` enum('positive','negative','edit') NOT NULL,
  `feedback_data` json DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_item` (`item_id`),
  KEY `idx_type` (`feedback_type`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `ai_harness_state`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `ai_harness_state` (
  `id` int NOT NULL AUTO_INCREMENT,
  `agent_name` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
  `session_id` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
  `current_feature_id` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `status` varchar(20) COLLATE utf8mb4_unicode_ci DEFAULT 'initializing',
  `context_size` int DEFAULT '0',
  `memory_usage` int DEFAULT '0',
  `started_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `last_heartbeat` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `meta_data` json DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=15 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `ai_insights_history`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `ai_insights_history` (
  `id` int NOT NULL AUTO_INCREMENT,
  `account_id` int NOT NULL,
  `type` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'strategic, ab_test, trends, sentiment, etc',
  `insights` json NOT NULL COMMENT 'Dados completos do insight gerado',
  `metadata` json DEFAULT NULL COMMENT 'Contexto adicional (options, filters, etc)',
  `confidence_score` decimal(5,2) DEFAULT NULL COMMENT 'Nível de confiança 0-100',
  `tokens_used` int DEFAULT NULL COMMENT 'Tokens consumidos da OpenAI API',
  `processing_time_ms` int DEFAULT NULL COMMENT 'Tempo de processamento em ms',
  `status` enum('success','partial','error') COLLATE utf8mb4_unicode_ci DEFAULT 'success',
  `error_message` text COLLATE utf8mb4_unicode_ci,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_account_id` (`account_id`),
  KEY `idx_type` (`type`),
  KEY `idx_created_at` (`created_at`),
  KEY `idx_account_type` (`account_id`,`type`),
  KEY `idx_status` (`status`)
) ENGINE=InnoDB AUTO_INCREMENT=80 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Histórico de insights gerados pela IA (GPT-4)';
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `ai_logs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `ai_logs` (
  `id` bigint NOT NULL AUTO_INCREMENT,
  `request_id` varchar(36) NOT NULL,
  `account_id` int DEFAULT NULL,
  `level` varchar(20) NOT NULL,
  `category` varchar(50) NOT NULL,
  `action` varchar(100) NOT NULL,
  `message` text NOT NULL,
  `context` json DEFAULT NULL,
  `duration_ms` int DEFAULT NULL,
  `ai_provider` varchar(50) DEFAULT NULL,
  `ai_model` varchar(50) DEFAULT NULL,
  `tokens_used` int DEFAULT NULL,
  `cost` decimal(10,6) DEFAULT NULL,
  `error_code` varchar(50) DEFAULT NULL,
  `stack_trace` text,
  `ip_address` varchar(45) DEFAULT NULL,
  `user_agent` varchar(255) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_request` (`request_id`),
  KEY `idx_account` (`account_id`),
  KEY `idx_level` (`level`),
  KEY `idx_category` (`category`),
  KEY `idx_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `ai_metrics`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `ai_metrics` (
  `id` int NOT NULL AUTO_INCREMENT,
  `account_id` int NOT NULL,
  `metric_type` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
  `metric_name` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `metric_value` decimal(15,4) NOT NULL,
  `dimensions` json DEFAULT NULL,
  `recorded_at` datetime DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_account_type` (`account_id`,`metric_type`),
  KEY `idx_recorded` (`recorded_at` DESC),
  KEY `idx_metric_name` (`metric_name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `ai_optimization_history`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `ai_optimization_history` (
  `id` int NOT NULL AUTO_INCREMENT,
  `account_id` int NOT NULL,
  `item_id` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
  `optimization_type` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
  `before_score` int DEFAULT '0',
  `after_score` int DEFAULT '0',
  `changes_json` json DEFAULT NULL,
  `created_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_account_id` (`account_id`),
  KEY `idx_item_id` (`item_id`),
  KEY `idx_optimization_type` (`optimization_type`),
  KEY `idx_created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `ai_optimization_jobs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `ai_optimization_jobs` (
  `id` int NOT NULL AUTO_INCREMENT,
  `job_type` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
  `account_id` int NOT NULL,
  `payload` json DEFAULT NULL,
  `result` json DEFAULT NULL,
  `priority` int DEFAULT '0',
  `status` enum('pending','processing','completed','failed') COLLATE utf8mb4_unicode_ci DEFAULT 'pending',
  `error` text COLLATE utf8mb4_unicode_ci,
  `scheduled_at` datetime DEFAULT NULL,
  `started_at` datetime DEFAULT NULL,
  `completed_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_status` (`status`),
  KEY `idx_account_id` (`account_id`),
  KEY `idx_priority` (`priority`),
  KEY `idx_scheduled_at` (`scheduled_at`),
  KEY `idx_created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `ai_optimization_logs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `ai_optimization_logs` (
  `id` int NOT NULL AUTO_INCREMENT,
  `item_id` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
  `optimization_type` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
  `field_name` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `before_data` text COLLATE utf8mb4_unicode_ci,
  `after_data` text COLLATE utf8mb4_unicode_ci,
  `ai_model` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `tokens_used` int DEFAULT '0',
  `cost_usd` decimal(10,4) DEFAULT '0.0000',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `status` varchar(20) COLLATE utf8mb4_unicode_ci DEFAULT 'pending',
  `error_message` text COLLATE utf8mb4_unicode_ci,
  `user_id` int NOT NULL,
  `account_id` int DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_item` (`item_id`),
  KEY `idx_type` (`optimization_type`),
  KEY `idx_status` (`status`),
  KEY `idx_user` (`user_id`),
  KEY `idx_created` (`created_at`),
  KEY `idx_account_id` (`account_id`),
  CONSTRAINT `ai_optimization_logs_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `ai_optimization_logs_ibfk_2` FOREIGN KEY (`account_id`) REFERENCES `ml_accounts` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `ai_optimization_queue`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `ai_optimization_queue` (
  `id` int NOT NULL AUTO_INCREMENT,
  `batch_id` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `item_id` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
  `options` json NOT NULL,
  `priority` int DEFAULT '5' COMMENT 'Higher number = higher priority',
  `status` enum('pending','processing','completed','failed') COLLATE utf8mb4_unicode_ci DEFAULT 'pending',
  `attempts` int DEFAULT '0',
  `max_attempts` int DEFAULT '3',
  `result` json DEFAULT NULL COMMENT 'Optimization results',
  `error` text COLLATE utf8mb4_unicode_ci,
  `duration_seconds` decimal(10,2) DEFAULT NULL,
  `cost` decimal(10,4) DEFAULT NULL COMMENT 'Cost in USD',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `started_at` timestamp NULL DEFAULT NULL,
  `completed_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_batch_id` (`batch_id`),
  KEY `idx_item_id` (`item_id`),
  KEY `idx_status` (`status`),
  KEY `idx_priority` (`priority`,`created_at`),
  KEY `idx_created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Queue for AI optimization batch processing';
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `ai_optimization_roi`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `ai_optimization_roi` (
  `id` int NOT NULL AUTO_INCREMENT,
  `item_id` varchar(50) NOT NULL,
  `optimization_date` date NOT NULL,
  `optimization_type` varchar(50) NOT NULL,
  `ai_cost` decimal(8,4) DEFAULT '0.0000',
  `revenue_before` decimal(12,2) DEFAULT '0.00',
  `revenue_after` decimal(12,2) DEFAULT '0.00',
  `revenue_change` decimal(12,2) DEFAULT '0.00',
  `roi_percentage` decimal(8,2) DEFAULT '0.00',
  `analysis_period_days` int DEFAULT '30',
  `status` enum('pending','calculating','complete') DEFAULT 'pending',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_item` (`item_id`),
  KEY `idx_date` (`optimization_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `ai_performance_tracking`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `ai_performance_tracking` (
  `id` int NOT NULL AUTO_INCREMENT,
  `item_id` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
  `optimization_id` int DEFAULT NULL COMMENT 'Reference to audit_log id',
  `date` date NOT NULL,
  `views` int DEFAULT '0',
  `visits` int DEFAULT '0',
  `sales` int DEFAULT '0',
  `revenue` decimal(10,2) DEFAULT '0.00',
  `questions` int DEFAULT '0',
  `favorites` int DEFAULT '0',
  `position` int DEFAULT NULL COMMENT 'Search ranking position',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_item_date` (`item_id`,`date`),
  KEY `idx_item_id` (`item_id`),
  KEY `idx_optimization_id` (`optimization_id`),
  KEY `idx_date` (`date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Daily performance metrics for optimized items';
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `ai_predictions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `ai_predictions` (
  `id` int NOT NULL AUTO_INCREMENT,
  `account_id` int DEFAULT NULL,
  `item_id` varchar(50) NOT NULL,
  `prediction_type` enum('views','ctr','conversion','revenue') NOT NULL,
  `predicted_value` decimal(15,4) NOT NULL,
  `confidence` decimal(5,4) DEFAULT '0.5000',
  `actual_value` decimal(15,4) DEFAULT NULL,
  `prediction_date` date NOT NULL,
  `evaluation_date` date DEFAULT NULL,
  `is_accurate` tinyint(1) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_item` (`item_id`),
  KEY `idx_type` (`prediction_type`),
  KEY `idx_date` (`prediction_date`)
) ENGINE=InnoDB AUTO_INCREMENT=5 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `ai_prompt_adjustments`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `ai_prompt_adjustments` (
  `id` int NOT NULL AUTO_INCREMENT,
  `account_id` int DEFAULT NULL,
  `category_id` varchar(50) DEFAULT NULL,
  `prompt_type` varchar(50) NOT NULL,
  `adjustment_key` varchar(100) NOT NULL,
  `adjustment_value` text NOT NULL,
  `performance_score` decimal(5,2) DEFAULT '0.00',
  `is_active` tinyint(1) DEFAULT '1',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_adjustment` (`account_id`,`category_id`,`prompt_type`,`adjustment_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `ai_success_patterns`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `ai_success_patterns` (
  `id` int NOT NULL AUTO_INCREMENT,
  `account_id` int DEFAULT NULL,
  `category_id` varchar(50) DEFAULT NULL,
  `pattern_type` varchar(50) NOT NULL,
  `pattern_data` json NOT NULL,
  `success_score` decimal(5,2) DEFAULT '0.00',
  `sample_size` int DEFAULT '0',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_category_type` (`category_id`,`pattern_type`)
) ENGINE=InnoDB AUTO_INCREMENT=10 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `ai_training_data`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `ai_training_data` (
  `id` int NOT NULL AUTO_INCREMENT,
  `account_id` int DEFAULT NULL,
  `item_id` varchar(50) NOT NULL,
  `category_id` varchar(50) DEFAULT NULL,
  `data_type` enum('title','description','attributes','image') NOT NULL,
  `original_content` text NOT NULL,
  `optimized_content` text NOT NULL,
  `score_before` int DEFAULT NULL,
  `score_after` int DEFAULT NULL,
  `conversion_before` decimal(5,4) DEFAULT '0.0000',
  `conversion_after` decimal(5,4) DEFAULT '0.0000',
  `is_successful` tinyint(1) DEFAULT '0',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_account` (`account_id`),
  KEY `idx_category` (`category_id`),
  KEY `idx_type` (`data_type`),
  KEY `idx_success` (`is_successful`)
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `alert_notifications`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `alert_notifications` (
  `id` bigint NOT NULL AUTO_INCREMENT,
  `type` enum('EMAIL','TELEGRAM','WEBHOOK','SMS') COLLATE utf8mb4_unicode_ci NOT NULL,
  `recipient` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `title` varchar(500) COLLATE utf8mb4_unicode_ci NOT NULL,
  `message` text COLLATE utf8mb4_unicode_ci NOT NULL,
  `severity` enum('LOW','MEDIUM','HIGH','CRITICAL') COLLATE utf8mb4_unicode_ci DEFAULT 'MEDIUM',
  `status` enum('PENDING','SENT','FAILED','RETRY') COLLATE utf8mb4_unicode_ci DEFAULT 'PENDING',
  `attempts` int DEFAULT '0',
  `max_attempts` int DEFAULT '3',
  `sent_at` timestamp NULL DEFAULT NULL,
  `error_message` text COLLATE utf8mb4_unicode_ci,
  `metadata` json DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_status` (`status`),
  KEY `idx_type` (`type`),
  KEY `idx_severity` (`severity`),
  KEY `idx_created` (`created_at`)
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `alerts`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `alerts` (
  `id` int NOT NULL AUTO_INCREMENT,
  `ml_account_id` int DEFAULT NULL,
  `type` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
  `severity` enum('info','warning','danger','success') COLLATE utf8mb4_unicode_ci DEFAULT 'info',
  `message` text COLLATE utf8mb4_unicode_ci NOT NULL,
  `data` json DEFAULT NULL,
  `read_at` datetime DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_account_id` (`ml_account_id`),
  KEY `idx_type` (`type`),
  KEY `idx_severity` (`severity`),
  KEY `idx_read_at` (`read_at`),
  KEY `idx_created_at` (`created_at`),
  KEY `idx_account_type` (`ml_account_id`,`type`),
  KEY `idx_type_created` (`type`,`created_at`),
  KEY `idx_unread` (`read_at`,`created_at`),
  CONSTRAINT `alerts_ibfk_1` FOREIGN KEY (`ml_account_id`) REFERENCES `ml_accounts` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=13213 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `algorithm_monitoring`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `algorithm_monitoring` (
  `id` int NOT NULL AUTO_INCREMENT,
  `account_id` varchar(100) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `detection_timestamp` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `change_type` enum('ranking','search','recommendation','pricing','visibility') COLLATE utf8mb4_general_ci NOT NULL,
  `severity` enum('minor','moderate','major','critical') COLLATE utf8mb4_general_ci NOT NULL,
  `confidence` decimal(5,4) NOT NULL,
  `affected_categories` json DEFAULT NULL,
  `detected_patterns` json DEFAULT NULL,
  `impact_assessment` json DEFAULT NULL,
  `adaptation_strategies` json DEFAULT NULL,
  `monitoring_alerts` json DEFAULT NULL,
  `resolved` tinyint(1) DEFAULT '0',
  `resolution_data` json DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_account_severity` (`account_id`,`severity`),
  KEY `idx_change_type` (`change_type`),
  KEY `idx_detection_time` (`detection_timestamp`),
  KEY `idx_resolved` (`resolved`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `api_metrics`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `api_metrics` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `account_id` int NOT NULL,
  `endpoint` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `method` varchar(10) COLLATE utf8mb4_unicode_ci NOT NULL,
  `status_code` smallint unsigned DEFAULT NULL,
  `response_time` decimal(10,4) NOT NULL COMMENT 'Segundos',
  `request_size` int unsigned DEFAULT '0' COMMENT 'Bytes',
  `response_size` int unsigned DEFAULT '0' COMMENT 'Bytes',
  `error_message` text COLLATE utf8mb4_unicode_ci,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_account_endpoint` (`account_id`,`endpoint`),
  KEY `idx_status` (`status_code`),
  KEY `idx_response_time` (`response_time`),
  KEY `idx_created_at` (`created_at`),
  KEY `idx_account_id` (`account_id`),
  CONSTRAINT `api_metrics_ibfk_1` FOREIGN KEY (`account_id`) REFERENCES `ml_accounts` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `api_tokens`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `api_tokens` (
  `id` int NOT NULL AUTO_INCREMENT,
  `user_id` int NOT NULL,
  `token` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `token_prefix` varchar(8) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '' COMMENT 'First 8 chars of raw token for UI display',
  `name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `scopes` json DEFAULT NULL,
  `is_active` tinyint(1) DEFAULT '1',
  `last_used_at` timestamp NULL DEFAULT NULL,
  `expires_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_token` (`token`),
  KEY `idx_user` (`user_id`)
) ENGINE=InnoDB AUTO_INCREMENT=5 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `assistant_action_runs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `assistant_action_runs` (
  `id` int NOT NULL AUTO_INCREMENT,
  `account_id` int NOT NULL,
  `user_id` int DEFAULT NULL,
  `api_token_id` int DEFAULT NULL,
  `job_id` int DEFAULT NULL,
  `action` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
  `idempotency_key` varchar(128) COLLATE utf8mb4_unicode_ci NOT NULL,
  `status` enum('queued','processing','completed','failed') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'queued',
  `attempts` int NOT NULL DEFAULT '0',
  `max_attempts` int NOT NULL DEFAULT '3',
  `parameters` json NOT NULL,
  `result` json DEFAULT NULL,
  `error_message` text COLLATE utf8mb4_unicode_ci,
  `started_at` datetime DEFAULT NULL,
  `completed_at` datetime DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_account_idempotency` (`account_id`,`idempotency_key`),
  KEY `idx_status_created` (`status`,`created_at`),
  KEY `idx_job_id` (`job_id`),
  KEY `idx_account_action_created` (`account_id`,`action`,`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `assistant_events`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `assistant_events` (
  `id` int NOT NULL AUTO_INCREMENT,
  `account_id` int DEFAULT NULL,
  `source` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
  `external_event_id` varchar(128) COLLATE utf8mb4_unicode_ci NOT NULL,
  `event_type` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `occurred_at` datetime DEFAULT NULL,
  `payload` json NOT NULL,
  `status` enum('received','processed','failed') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'received',
  `error_message` text COLLATE utf8mb4_unicode_ci,
  `processed_at` datetime DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_source_external_event` (`source`,`external_event_id`),
  KEY `idx_account_type_created` (`account_id`,`event_type`,`created_at`),
  KEY `idx_status_created` (`status`,`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `audit_logs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `audit_logs` (
  `id` int NOT NULL AUTO_INCREMENT,
  `user_id` int DEFAULT NULL,
  `ml_account_id` int DEFAULT NULL,
  `action` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `resource` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `ip_address` varchar(45) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `user_agent` text COLLATE utf8mb4_unicode_ci,
  `data` json DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `details` text COLLATE utf8mb4_unicode_ci,
  `old_value` json DEFAULT NULL,
  `new_value` json DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_user_id` (`user_id`),
  KEY `idx_account_id` (`ml_account_id`),
  KEY `idx_action` (`action`),
  KEY `idx_created_at` (`created_at`),
  KEY `idx_audit_account_created` (`ml_account_id`,`created_at`)
) ENGINE=InnoDB AUTO_INCREMENT=620 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `auth_blocked_ips`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `auth_blocked_ips` (
  `id` int NOT NULL AUTO_INCREMENT,
  `ip_address` varchar(45) NOT NULL,
  `country_code` char(2) DEFAULT NULL COMMENT 'ISO country code',
  `country_name` varchar(100) DEFAULT NULL COMMENT 'Country name',
  `city` varchar(100) DEFAULT NULL COMMENT 'City name',
  `reason` varchar(255) DEFAULT NULL,
  `failure_count` int DEFAULT '0',
  `blocked_at` datetime DEFAULT CURRENT_TIMESTAMP,
  `expires_at` datetime DEFAULT NULL,
  `is_permanent` tinyint(1) DEFAULT '0',
  `created_by` varchar(100) DEFAULT 'monitor-auth-failures',
  PRIMARY KEY (`id`),
  KEY `idx_ip` (`ip_address`),
  KEY `idx_expires` (`expires_at`),
  KEY `idx_country` (`country_code`)
) ENGINE=InnoDB AUTO_INCREMENT=30029 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `auth_failure_log`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `auth_failure_log` (
  `id` int NOT NULL AUTO_INCREMENT,
  `ip_address` varchar(45) NOT NULL,
  `country_code` char(2) DEFAULT NULL COMMENT 'ISO country code',
  `country_name` varchar(100) DEFAULT NULL COMMENT 'Country name',
  `city` varchar(100) DEFAULT NULL COMMENT 'City name',
  `latitude` decimal(10,7) DEFAULT NULL COMMENT 'Latitude',
  `longitude` decimal(10,7) DEFAULT NULL COMMENT 'Longitude',
  `user_agent` text,
  `username` varchar(255) DEFAULT NULL,
  `failure_type` varchar(50) DEFAULT NULL,
  `log_line` text,
  `detected_at` datetime DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_ip_date` (`ip_address`,`detected_at`),
  KEY `idx_detected` (`detected_at`),
  KEY `idx_country` (`country_code`),
  KEY `idx_city` (`city`)
) ENGINE=InnoDB AUTO_INCREMENT=60573790 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `auto_responses`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `auto_responses` (
  `id` int NOT NULL AUTO_INCREMENT,
  `account_id` int NOT NULL,
  `trigger_keyword` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `response_message` text COLLATE utf8mb4_unicode_ci NOT NULL,
  `enabled` tinyint(1) DEFAULT '1',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_trigger` (`account_id`,`trigger_keyword`),
  KEY `idx_account` (`account_id`),
  KEY `idx_enabled` (`enabled`),
  CONSTRAINT `auto_responses_ibfk_1` FOREIGN KEY (`account_id`) REFERENCES `ml_accounts` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `automation_rules`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `automation_rules` (
  `id` int NOT NULL AUTO_INCREMENT,
  `automation_id` varchar(100) COLLATE utf8mb4_general_ci NOT NULL,
  `conditions` json NOT NULL,
  `actions` json NOT NULL,
  `status` varchar(20) COLLATE utf8mb4_general_ci NOT NULL,
  `execution_count` int DEFAULT '0',
  `last_execution` datetime DEFAULT NULL,
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `automation_id` (`automation_id`),
  KEY `idx_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `automation_workflows`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `automation_workflows` (
  `id` int NOT NULL AUTO_INCREMENT,
  `workflow_id` varchar(100) COLLATE utf8mb4_general_ci NOT NULL,
  `type` varchar(50) COLLATE utf8mb4_general_ci NOT NULL,
  `definition` json NOT NULL,
  `status` varchar(20) COLLATE utf8mb4_general_ci NOT NULL,
  `priority` varchar(10) COLLATE utf8mb4_general_ci NOT NULL,
  `execution_plan` json DEFAULT NULL,
  `progress` int DEFAULT '0',
  `retry_count` int DEFAULT '0',
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `workflow_id` (`workflow_id`),
  KEY `idx_status` (`status`),
  KEY `idx_priority` (`priority`),
  KEY `idx_type` (`type`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `autonomous_strategies`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `autonomous_strategies` (
  `id` int NOT NULL AUTO_INCREMENT,
  `account_id` varchar(100) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `strategy_name` varchar(200) COLLATE utf8mb4_general_ci NOT NULL,
  `strategy_type` enum('pricing','seo','timing','competition','comprehensive') COLLATE utf8mb4_general_ci NOT NULL,
  `strategy_complexity` enum('basic','intermediate','advanced','expert') COLLATE utf8mb4_general_ci NOT NULL,
  `strategy_conditions` json NOT NULL,
  `strategy_actions` json NOT NULL,
  `success_criteria` json DEFAULT NULL,
  `performance_metrics` json DEFAULT NULL,
  `usage_count` int DEFAULT '0',
  `success_count` int DEFAULT '0',
  `average_reward` decimal(10,6) DEFAULT '0.000000',
  `confidence_score` decimal(5,4) DEFAULT '0.0000',
  `last_used` timestamp NULL DEFAULT NULL,
  `last_success` timestamp NULL DEFAULT NULL,
  `is_active` tinyint(1) DEFAULT '1',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_account_type_complexity` (`account_id`,`strategy_type`,`strategy_complexity`),
  KEY `idx_success_rate` (`success_count`,`usage_count`),
  KEY `idx_confidence_score` (`confidence_score` DESC),
  KEY `idx_is_active` (`is_active`),
  KEY `idx_autonomous_strategies_composite` (`account_id`,`strategy_type`,`success_count` DESC,`confidence_score` DESC)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `autopilot_config`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `autopilot_config` (
  `id` int NOT NULL AUTO_INCREMENT,
  `account_id` int NOT NULL,
  `config_data` json DEFAULT NULL,
  `enabled` tinyint(1) DEFAULT '0',
  `config` json DEFAULT NULL,
  `last_run_at` timestamp NULL DEFAULT NULL,
  `next_run_at` timestamp NULL DEFAULT NULL,
  `total_runs` int DEFAULT '0',
  `total_optimizations` int DEFAULT '0',
  `success_rate` decimal(5,2) DEFAULT '0.00',
  `last_error` text COLLATE utf8mb4_unicode_ci,
  `budget_used` decimal(10,2) DEFAULT '0.00',
  `budget_limit` decimal(10,2) DEFAULT '100.00',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `account_id` (`account_id`),
  KEY `idx_account` (`account_id`),
  KEY `idx_enabled` (`enabled`),
  KEY `idx_next_run` (`next_run_at`)
) ENGINE=InnoDB AUTO_INCREMENT=10 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `autopilot_cycles`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `autopilot_cycles` (
  `id` int NOT NULL AUTO_INCREMENT,
  `cycle_id` varchar(100) COLLATE utf8mb4_general_ci NOT NULL,
  `account_id` varchar(100) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `status` enum('running','completed','failed','paused') COLLATE utf8mb4_general_ci DEFAULT 'running',
  `decisions_made` int DEFAULT '0',
  `executions_attempted` int DEFAULT '0',
  `executions_successful` int DEFAULT '0',
  `summary_data` json DEFAULT NULL,
  `market_context` json DEFAULT NULL,
  `started_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `completed_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `cycle_id` (`cycle_id`),
  KEY `idx_account_id` (`account_id`),
  KEY `idx_status` (`status`),
  KEY `idx_started_at` (`started_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `autopilot_execution_errors`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `autopilot_execution_errors` (
  `id` int NOT NULL AUTO_INCREMENT,
  `item_id` varchar(100) COLLATE utf8mb4_general_ci NOT NULL,
  `execution_id` varchar(100) COLLATE utf8mb4_general_ci NOT NULL,
  `record_id` varchar(100) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `error_message` text COLLATE utf8mb4_general_ci NOT NULL,
  `error_class` varchar(100) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `stack_trace` text COLLATE utf8mb4_general_ci,
  `recovery_attempted` tinyint(1) DEFAULT '0',
  `recovery_successful` tinyint(1) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_execution_id` (`execution_id`),
  KEY `idx_error_class` (`error_class`),
  KEY `idx_created_at` (`created_at`),
  KEY `idx_record_id` (`record_id`),
  CONSTRAINT `autopilot_execution_errors_ibfk_1` FOREIGN KEY (`execution_id`) REFERENCES `autopilot_execution_sessions` (`execution_id`) ON DELETE CASCADE,
  CONSTRAINT `autopilot_execution_errors_ibfk_2` FOREIGN KEY (`record_id`) REFERENCES `autopilot_execution_records` (`record_id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `autopilot_execution_records`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `autopilot_execution_records` (
  `id` int NOT NULL AUTO_INCREMENT,
  `record_id` varchar(100) COLLATE utf8mb4_general_ci NOT NULL,
  `execution_id` varchar(100) COLLATE utf8mb4_general_ci NOT NULL,
  `item_id` varchar(100) COLLATE utf8mb4_general_ci NOT NULL,
  `decision_data` json DEFAULT NULL,
  `execution_results` json DEFAULT NULL,
  `baseline_metrics` json DEFAULT NULL,
  `status` enum('executing','completed','failed','rolled_back') COLLATE utf8mb4_general_ci DEFAULT 'executing',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `completed_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `record_id` (`record_id`),
  KEY `idx_execution_id` (`execution_id`),
  KEY `idx_item_id` (`item_id`),
  KEY `idx_status` (`status`),
  KEY `idx_execution_records_composite` (`execution_id`,`status`,`item_id`),
  CONSTRAINT `autopilot_execution_records_ibfk_1` FOREIGN KEY (`execution_id`) REFERENCES `autopilot_execution_sessions` (`execution_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `autopilot_execution_sessions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `autopilot_execution_sessions` (
  `id` int NOT NULL AUTO_INCREMENT,
  `execution_id` varchar(100) COLLATE utf8mb4_general_ci NOT NULL,
  `account_id` varchar(100) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `decisions_data` json DEFAULT NULL,
  `status` enum('running','completed','completed_with_errors','failed','paused') COLLATE utf8mb4_general_ci DEFAULT 'running',
  `success_count` int DEFAULT '0',
  `total_count` int DEFAULT '0',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `started_at` timestamp NULL DEFAULT NULL,
  `completed_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `execution_id` (`execution_id`),
  KEY `idx_account_id` (`account_id`),
  KEY `idx_status` (`status`),
  KEY `idx_created_at` (`created_at`),
  KEY `idx_execution_sessions_composite` (`account_id`,`status`,`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET collation_connection  = utf8mb4_0900_ai_ci */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION' */ ;
DELIMITER ;;
/*!50003 CREATE*/  /*!50003 TRIGGER `update_autopilot_status_on_execution` AFTER INSERT ON `autopilot_execution_sessions` FOR EACH ROW BEGIN
    INSERT INTO autopilot_status (account_id, last_cycle_at, total_cycles)
    VALUES (NEW.account_id, NEW.started_at, 1)
    ON DUPLICATE KEY UPDATE 
        last_cycle_at = NEW.started_at,
        total_cycles = total_cycles + 1,
        updated_at = CURRENT_TIMESTAMP;
END */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
DROP TABLE IF EXISTS `autopilot_status`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `autopilot_status` (
  `id` int NOT NULL AUTO_INCREMENT,
  `account_id` varchar(100) COLLATE utf8mb4_general_ci NOT NULL,
  `status` enum('active','paused','disabled','maintenance') COLLATE utf8mb4_general_ci DEFAULT 'active',
  `last_cycle_at` timestamp NULL DEFAULT NULL,
  `next_cycle_at` timestamp NULL DEFAULT NULL,
  `total_cycles` int DEFAULT '0',
  `success_rate` decimal(5,4) DEFAULT '0.0000',
  `total_optimizations` int DEFAULT '0',
  `successful_optimizations` int DEFAULT '0',
  `average_roi` decimal(10,2) DEFAULT '0.00',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `account_id` (`account_id`),
  KEY `idx_account_id` (`account_id`),
  KEY `idx_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `autopilot_summary`;
/*!50001 DROP VIEW IF EXISTS `autopilot_summary`*/;
SET @saved_cs_client     = @@character_set_client;
/*!50503 SET character_set_client = utf8mb4 */;
/*!50001 CREATE VIEW `autopilot_summary` AS SELECT 
 1 AS `account_id`,
 1 AS `total_executions`,
 1 AS `total_cycles`,
 1 AS `avg_success_rate`,
 1 AS `total_optimizations`,
 1 AS `last_execution`,
 1 AS `current_status`*/;
SET character_set_client = @saved_cs_client;
DROP TABLE IF EXISTS `awa_scan_runs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `awa_scan_runs` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `account_id` int NOT NULL,
  `scope_json` json DEFAULT NULL COMMENT 'Categorias e parâmetros da varredura',
  `status` enum('pending','running','completed','failed') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'running',
  `sellers_found` int unsigned NOT NULL DEFAULT '0',
  `items_found` int unsigned NOT NULL DEFAULT '0',
  `started_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `finished_at` timestamp NULL DEFAULT NULL,
  `error_message` text COLLATE utf8mb4_unicode_ci,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_awa_scan_runs_account_created` (`account_id`,`created_at`),
  KEY `idx_awa_scan_runs_account_status` (`account_id`,`status`),
  CONSTRAINT `fk_awa_scan_runs_account` FOREIGN KEY (`account_id`) REFERENCES `ml_accounts` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=123 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Execuções de varredura AWA de sellers no Mercado Livre por conta';
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `awa_seller_identification`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `awa_seller_identification` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `seller_registry_id` int unsigned NOT NULL,
  `cnpj` varchar(20) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'Formatado: XX.XXX.XXX/XXXX-XX',
  `razao_social` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `source_type` enum('manual','authorized_ml_account','internal_registry','external_registry','website_review','legal_team_validation') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'manual',
  `source_reference` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'Referência da origem (URL, ID, etc.)',
  `confidence_score` tinyint unsigned NOT NULL DEFAULT '50' COMMENT '0-100',
  `verification_status` enum('verified','pending','not_available','conflict') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'pending',
  `verified_at` timestamp NULL DEFAULT NULL,
  `notes` text COLLATE utf8mb4_unicode_ci,
  `created_by` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_asi_registry` (`seller_registry_id`),
  KEY `idx_asi_status` (`verification_status`),
  CONSTRAINT `fk_asi_registry` FOREIGN KEY (`seller_registry_id`) REFERENCES `awa_seller_registry` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Identificação jurídica e comercial dos sellers AWA';
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `awa_seller_items`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `awa_seller_items` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `account_id` int NOT NULL,
  `seller_registry_id` int unsigned NOT NULL,
  `ml_item_id` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'ex: MLB123456789',
  `title` varchar(512) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `category_id` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `price` decimal(12,2) DEFAULT NULL,
  `status` varchar(30) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'active, paused, closed',
  `brand_match_type` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'unclassified',
  `has_brand_attribute` tinyint(1) NOT NULL DEFAULT '0',
  `evidence_json` json DEFAULT NULL COMMENT 'Dados brutos de evidência',
  `first_seen_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `last_seen_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_awa_seller_items_account_item` (`account_id`,`ml_item_id`),
  KEY `idx_awa_seller_items_registry` (`seller_registry_id`),
  KEY `idx_awa_seller_items_account_last_seen` (`account_id`,`last_seen_at`),
  KEY `idx_awa_seller_items_account_match` (`account_id`,`brand_match_type`),
  KEY `idx_awa_seller_items_category` (`category_id`),
  CONSTRAINT `fk_awa_seller_items_account` FOREIGN KEY (`account_id`) REFERENCES `ml_accounts` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_awa_seller_items_registry` FOREIGN KEY (`seller_registry_id`) REFERENCES `awa_seller_registry` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=119 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Anúncios AWA observados por seller no Mercado Livre';
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `awa_seller_registry`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `awa_seller_registry` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `account_id` int NOT NULL,
  `seller_id` bigint unsigned NOT NULL COMMENT 'ML user id',
  `nickname` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `permalink` varchar(512) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `city` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `state` varchar(120) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `user_type` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'normal, brand',
  `reputation_level` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'ex: 5_green, 4_light_green',
  `power_seller_status` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `items_count` int unsigned NOT NULL DEFAULT '0' COMMENT 'Anúncios AWA detectados',
  `categories_json` json DEFAULT NULL COMMENT 'Categorias em que aparece',
  `first_seen_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `last_seen_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `last_scan_id` int unsigned DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_awa_seller_registry_account_seller` (`account_id`,`seller_id`),
  KEY `idx_awa_seller_registry_account_last_seen` (`account_id`,`last_seen_at`),
  KEY `idx_awa_seller_registry_account_active` (`account_id`,`is_active`),
  KEY `idx_awa_seller_registry_reputation` (`reputation_level`),
  KEY `fk_awa_seller_registry_last_scan` (`last_scan_id`),
  CONSTRAINT `fk_awa_seller_registry_account` FOREIGN KEY (`account_id`) REFERENCES `ml_accounts` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_awa_seller_registry_last_scan` FOREIGN KEY (`last_scan_id`) REFERENCES `awa_scan_runs` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Registry consolidado de sellers AWA detectados no Mercado Livre';
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `background_jobs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `background_jobs` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `job_type` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `payload` json DEFAULT NULL,
  `status` enum('pending','processing','completed','failed','cancelled') COLLATE utf8mb4_unicode_ci DEFAULT 'pending',
  `priority` tinyint unsigned DEFAULT '5' COMMENT '1-10, menor = maior prioridade',
  `attempts` tinyint unsigned DEFAULT '0',
  `max_attempts` tinyint unsigned DEFAULT '3',
  `result` json DEFAULT NULL,
  `error_message` text COLLATE utf8mb4_unicode_ci,
  `started_at` timestamp NULL DEFAULT NULL,
  `completed_at` timestamp NULL DEFAULT NULL,
  `scheduled_for` timestamp NULL DEFAULT NULL COMMENT 'Execução agendada',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_status_priority` (`status`,`priority`),
  KEY `idx_job_type` (`job_type`),
  KEY `idx_scheduled` (`scheduled_for`,`status`),
  KEY `idx_created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `blocked_ips`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `blocked_ips` (
  `id` int NOT NULL AUTO_INCREMENT,
  `ip_address` varchar(45) COLLATE utf8mb4_unicode_ci NOT NULL,
  `reason` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `blocked_by` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT 'system' COMMENT 'Quem bloqueou (system, admin, fail2ban)',
  `blocked_until` timestamp NULL DEFAULT NULL COMMENT 'NULL = permanente',
  `attempts` int DEFAULT '0' COMMENT 'Número de tentativas antes do bloqueio',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `ip_address` (`ip_address`),
  KEY `idx_ip_address` (`ip_address`),
  KEY `idx_blocked_until` (`blocked_until`)
) ENGINE=InnoDB AUTO_INCREMENT=28 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `brand_analysis_history`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `brand_analysis_history` (
  `id` int NOT NULL AUTO_INCREMENT,
  `category_id` varchar(50) NOT NULL,
  `brand` varchar(100) NOT NULL,
  `analysis_data` json DEFAULT NULL,
  `total_items` int DEFAULT '0',
  `avg_price` decimal(12,2) DEFAULT NULL,
  `min_price` decimal(12,2) DEFAULT NULL,
  `max_price` decimal(12,2) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_category_brand` (`category_id`,`brand`),
  KEY `idx_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `brand_items`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `brand_items` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `search_id` bigint unsigned NOT NULL COMMENT 'FK → brand_searches.id',
  `seller_id` bigint unsigned NOT NULL COMMENT 'FK → brand_sellers.seller_id',
  `item_id` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'ID do anúncio no ML (ex: MLB123456)',
  `title` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `category_id` varchar(20) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `category_name` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `price` decimal(12,2) DEFAULT NULL,
  `currency_id` char(3) COLLATE utf8mb4_unicode_ci DEFAULT 'BRL',
  `condition` enum('new','used','not_specified') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'new',
  `listing_type` varchar(30) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'gold_pro, gold_special, gold...',
  `permalink` varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `thumbnail` varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `available_qty` int unsigned DEFAULT NULL,
  `status` varchar(20) COLLATE utf8mb4_unicode_ci DEFAULT 'active',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_search_item` (`search_id`,`item_id`),
  KEY `idx_search_id` (`search_id`),
  KEY `idx_seller_id` (`seller_id`),
  KEY `idx_item_id` (`item_id`),
  KEY `idx_category` (`category_id`),
  KEY `idx_price` (`price`),
  CONSTRAINT `fk_bi_search` FOREIGN KEY (`search_id`) REFERENCES `brand_searches` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Anúncios coletados por busca de marca';
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `brand_search_items`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `brand_search_items` (
  `id` int NOT NULL AUTO_INCREMENT,
  `run_id` int NOT NULL,
  `item_id` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL,
  `title` varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `price` decimal(12,2) DEFAULT NULL,
  `currency_id` varchar(10) COLLATE utf8mb4_unicode_ci DEFAULT 'BRL',
  `permalink` varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `category_id` varchar(20) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `category_name` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `seller_id` int DEFAULT NULL,
  `seller_nickname` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `available_quantity` int NOT NULL DEFAULT '0',
  `sold_quantity` int NOT NULL DEFAULT '0',
  `condition` enum('new','used','not_specified') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'not_specified',
  `listing_type_id` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `thumbnail` varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_bsi_run` (`run_id`),
  KEY `idx_bsi_item` (`item_id`),
  KEY `idx_bsi_seller` (`seller_id`),
  CONSTRAINT `fk_bsi_run` FOREIGN KEY (`run_id`) REFERENCES `brand_search_runs` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `brand_search_runs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `brand_search_runs` (
  `id` int NOT NULL AUTO_INCREMENT,
  `account_id` int DEFAULT NULL,
  `brand_query` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `brand_value_id` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `site_id` varchar(10) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'MLB',
  `categories_filter` json DEFAULT NULL,
  `total_found` int NOT NULL DEFAULT '0',
  `total_fetched` int NOT NULL DEFAULT '0',
  `status` enum('running','done','error') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'running',
  `error_message` text COLLATE utf8mb4_unicode_ci,
  `options` json DEFAULT NULL,
  `started_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `finished_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_bsr_account` (`account_id`),
  KEY `idx_bsr_status` (`status`),
  KEY `idx_bsr_started` (`started_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `brand_search_sellers`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `brand_search_sellers` (
  `id` int NOT NULL AUTO_INCREMENT,
  `run_id` int NOT NULL,
  `seller_id` int NOT NULL,
  `nickname` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `reputation_level` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `reputation_score` decimal(5,2) DEFAULT NULL,
  `power_seller_status` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `items_in_run` int NOT NULL DEFAULT '0',
  `profile_url` varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_bss_run` (`run_id`),
  KEY `idx_bss_seller` (`seller_id`),
  CONSTRAINT `fk_bss_run` FOREIGN KEY (`run_id`) REFERENCES `brand_search_runs` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `brand_searches`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `brand_searches` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `account_id` bigint unsigned NOT NULL COMMENT 'Conta ML associada (multi-conta)',
  `brand_id` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'ID da marca no ML (ex: 7297804)',
  `brand_name` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'Nome da marca (ex: AWA)',
  `site_id` varchar(10) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'MLB' COMMENT 'Site ML (MLB, MLA...)',
  `category_id` varchar(20) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'Filtro de categoria aplicado (NULL = todas)',
  `status` enum('pending','running','completed','failed') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'pending',
  `total_items` int unsigned NOT NULL DEFAULT '0' COMMENT 'Total de anúncios encontrados',
  `total_sellers` int unsigned NOT NULL DEFAULT '0' COMMENT 'Total de vendedores únicos',
  `progress` tinyint unsigned NOT NULL DEFAULT '0' COMMENT 'Progresso da coleta 0-100',
  `error_message` text COLLATE utf8mb4_unicode_ci COMMENT 'Mensagem de erro se status=failed',
  `started_at` datetime DEFAULT NULL,
  `completed_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_account_brand` (`account_id`,`brand_id`),
  KEY `idx_status` (`status`),
  KEY `idx_brand_name` (`brand_name`),
  KEY `idx_created_at` (`created_at`)
) ENGINE=InnoDB AUTO_INCREMENT=6 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Buscas de anúncios por marca no Mercado Livre';
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `brand_sellers`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `brand_sellers` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `search_id` bigint unsigned NOT NULL COMMENT 'FK → brand_searches.id',
  `seller_id` bigint unsigned NOT NULL COMMENT 'ID numérico do vendedor no ML',
  `nickname` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'Nickname público da loja',
  `seller_type` varchar(30) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'normal, brand, real_estate_agency...',
  `permalink` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'URL do perfil no ML',
  `reputation_level` varchar(20) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'platinum, gold, silver, bronze, new',
  `reputation_score` tinyint unsigned DEFAULT NULL COMMENT 'Score 0-100 calculado',
  `power_seller_status` varchar(20) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'gold, gold_special, null',
  `total_items_brand` int unsigned NOT NULL DEFAULT '0' COMMENT 'Anúncios desta marca neste seller',
  `avg_price` decimal(12,2) DEFAULT NULL COMMENT 'Preço médio dos anúncios da marca',
  `site_status` varchar(20) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'active, paused, suspended...',
  `country_id` char(2) COLLATE utf8mb4_unicode_ci DEFAULT 'BR',
  `city` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `state` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `trend` enum('up','down','stable') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'stable',
  `last_synced_at` datetime DEFAULT NULL COMMENT 'Última sincronização deste vendedor',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_search_seller` (`search_id`,`seller_id`),
  KEY `idx_seller_id` (`seller_id`),
  KEY `idx_search_id` (`search_id`),
  KEY `idx_reputation` (`reputation_level`),
  KEY `idx_total_items` (`total_items_brand`),
  CONSTRAINT `fk_bs_search` FOREIGN KEY (`search_id`) REFERENCES `brand_searches` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Vendedores únicos por busca de marca';
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `brevo_contacts`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `brevo_contacts` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `email` varchar(255) NOT NULL,
  `brevo_contact_id` varchar(64) DEFAULT NULL,
  `attributes_json` longtext,
  `list_ids_json` longtext,
  `email_blacklisted` tinyint(1) NOT NULL DEFAULT '0',
  `sms_blacklisted` tinyint(1) NOT NULL DEFAULT '0',
  `raw_json` longtext,
  `last_synced_at` datetime DEFAULT NULL,
  `deleted_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_brevo_contacts_email` (`email`),
  KEY `idx_brevo_contacts_deleted_at` (`deleted_at`),
  KEY `idx_brevo_contacts_last_synced_at` (`last_synced_at`)
) ENGINE=InnoDB AUTO_INCREMENT=19 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `brevo_lists`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `brevo_lists` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `brevo_list_id` int NOT NULL,
  `name` varchar(255) NOT NULL,
  `folder_id` int DEFAULT NULL,
  `raw_json` longtext,
  `last_synced_at` datetime DEFAULT NULL,
  `deleted_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_brevo_lists_brevo_list_id` (`brevo_list_id`),
  KEY `idx_brevo_lists_deleted_at` (`deleted_at`),
  KEY `idx_brevo_lists_last_synced_at` (`last_synced_at`)
) ENGINE=InnoDB AUTO_INCREMENT=19 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `brevo_sync_runs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `brevo_sync_runs` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `entity` varchar(32) NOT NULL,
  `status` varchar(32) NOT NULL,
  `started_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `finished_at` datetime DEFAULT NULL,
  `duration_ms` int DEFAULT NULL,
  `processed` int NOT NULL DEFAULT '0',
  `errors` int NOT NULL DEFAULT '0',
  `upstream_status` int DEFAULT NULL,
  `message` text,
  `meta_json` longtext,
  PRIMARY KEY (`id`),
  KEY `idx_brevo_sync_runs_entity_started` (`entity`,`started_at`),
  KEY `idx_brevo_sync_runs_status` (`status`)
) ENGINE=InnoDB AUTO_INCREMENT=10 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `bulk_seo_jobs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `bulk_seo_jobs` (
  `id` int NOT NULL AUTO_INCREMENT,
  `job_id` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `account_id` int NOT NULL,
  `user_id` int DEFAULT NULL,
  `status` enum('pending','queued','processing','completed','failed') COLLATE utf8mb4_unicode_ci DEFAULT 'pending',
  `total_items` int DEFAULT '0',
  `processed_items` int DEFAULT '0',
  `successful_items` int DEFAULT '0',
  `failed_items` int DEFAULT '0',
  `job_data` json DEFAULT NULL,
  `results` json DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `started_at` timestamp NULL DEFAULT NULL,
  `completed_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `job_id` (`job_id`),
  KEY `idx_account_status` (`account_id`,`status`),
  KEY `idx_job_id` (`job_id`)
) ENGINE=InnoDB AUTO_INCREMENT=33 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `cache_entries`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `cache_entries` (
  `id` int NOT NULL AUTO_INCREMENT,
  `cache_key` varchar(255) NOT NULL,
  `data` longtext NOT NULL,
  `type` varchar(50) NOT NULL,
  `compressed` tinyint(1) DEFAULT '0',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `expires_at` timestamp NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `cache_key` (`cache_key`),
  KEY `idx_cache_key_expires` (`cache_key`,`expires_at`),
  KEY `idx_type_expires` (`type`,`expires_at`)
) ENGINE=InnoDB AUTO_INCREMENT=526 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `cache_stats`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `cache_stats` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `driver` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
  `hits` bigint unsigned DEFAULT '0',
  `misses` bigint unsigned DEFAULT '0',
  `writes` bigint unsigned DEFAULT '0',
  `memory_usage` bigint unsigned DEFAULT '0' COMMENT 'Bytes',
  `recorded_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_recorded_at` (`recorded_at`),
  KEY `idx_driver` (`driver`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `catalog_clone_job_items`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `catalog_clone_job_items` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `job_id` varchar(64) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'Reference to catalog_clone_jobs.job_id',
  `source_item_id` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'Original ML item ID',
  `source_snapshot` json DEFAULT NULL COMMENT 'Captured data: title, category, brand, price, is_catalog, etc.',
  `target_item_id` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'Created ML item ID on success',
  `status` enum('pending','processing','completed','failed','skipped') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'pending',
  `is_catalog` tinyint(1) NOT NULL DEFAULT '0' COMMENT 'Whether source item is catalog',
  `brand` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'Extracted brand for facets',
  `error_message` text COLLATE utf8mb4_unicode_ci,
  `error_code` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'Structured error code for reporting',
  `result` json DEFAULT NULL COMMENT 'Additional result data, warnings, etc.',
  `attempts` tinyint unsigned NOT NULL DEFAULT '0' COMMENT 'Number of processing attempts',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `processed_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `post_actions_status` enum('pending','processing','completed','failed','skipped') COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `post_actions_result` json DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_job_id` (`job_id`),
  KEY `idx_status` (`status`),
  KEY `idx_source_item` (`source_item_id`),
  KEY `idx_brand` (`brand`),
  KEY `idx_is_catalog` (`is_catalog`),
  KEY `idx_job_status` (`job_id`,`status`)
) ENGINE=InnoDB AUTO_INCREMENT=7 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `catalog_clone_job_stats`;
/*!50001 DROP VIEW IF EXISTS `catalog_clone_job_stats`*/;
SET @saved_cs_client     = @@character_set_client;
/*!50503 SET character_set_client = utf8mb4 */;
/*!50001 CREATE VIEW `catalog_clone_job_stats` AS SELECT 
 1 AS `id`,
 1 AS `job_id`,
 1 AS `target_account_id`,
 1 AS `target_account_name`,
 1 AS `source_type`,
 1 AS `source_seller_id`,
 1 AS `status`,
 1 AS `total_items`,
 1 AS `successful_items`,
 1 AS `failed_items`,
 1 AS `skipped_items`,
 1 AS `success_rate`,
 1 AS `created_at`,
 1 AS `completed_at`,
 1 AS `duration_seconds`*/;
SET character_set_client = @saved_cs_client;
DROP TABLE IF EXISTS `catalog_clone_jobs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `catalog_clone_jobs` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `job_id` varchar(64) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'Unique identifier for the job',
  `target_account_id` int unsigned NOT NULL COMMENT 'Destination ML account ID',
  `target_account_nickname` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'Nickname of destination ML account for display',
  `source_type` enum('seller','item_ids','account') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'seller' COMMENT 'Origin type',
  `source_seller_id` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'ML Seller ID when source_type is seller',
  `source_account_id` int unsigned DEFAULT NULL COMMENT 'Internal account ID when source is connected account',
  `source_account_nickname` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'Nickname of source ML account for display',
  `status` enum('pending','queued','processing','completed','completed_with_errors','failed','cancelled') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'pending',
  `total_items` int unsigned NOT NULL DEFAULT '0',
  `processed_items` int unsigned NOT NULL DEFAULT '0',
  `successful_items` int unsigned NOT NULL DEFAULT '0',
  `failed_items` int unsigned NOT NULL DEFAULT '0',
  `skipped_items` int unsigned NOT NULL DEFAULT '0' COMMENT 'Duplicates or validation failures',
  `options` json DEFAULT NULL COMMENT 'Template, pricing rules, stock rules, flags',
  `error_message` text COLLATE utf8mb4_unicode_ci COMMENT 'General error message if job failed',
  `created_by_user_id` int unsigned DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `started_at` timestamp NULL DEFAULT NULL,
  `completed_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `template_id` int unsigned DEFAULT NULL COMMENT 'Reference to clone_templates.id',
  `template_slug` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'Template slug for quick reference',
  PRIMARY KEY (`id`),
  UNIQUE KEY `job_id` (`job_id`),
  KEY `idx_status` (`status`),
  KEY `idx_target_account` (`target_account_id`),
  KEY `idx_source_seller` (`source_seller_id`),
  KEY `idx_created_at` (`created_at`),
  KEY `idx_user` (`created_by_user_id`),
  KEY `idx_template` (`template_id`)
) ENGINE=InnoDB AUTO_INCREMENT=8 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `category_insights`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `category_insights` (
  `id` int NOT NULL AUTO_INCREMENT,
  `account_id` varchar(100) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `category_id` varchar(50) COLLATE utf8mb4_general_ci NOT NULL,
  `category_name` varchar(200) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `total_optimizations` int DEFAULT '0',
  `success_rate` decimal(5,4) DEFAULT '0.0000',
  `avg_impact` decimal(5,4) DEFAULT '0.0000',
  `best_strategies` json DEFAULT NULL,
  `seasonal_patterns` json DEFAULT NULL,
  `competition_level` varchar(20) COLLATE utf8mb4_general_ci DEFAULT 'medium',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_account_category` (`account_id`,`category_id`),
  KEY `idx_success_rate` (`success_rate`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `category_learning`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `category_learning` (
  `id` int NOT NULL AUTO_INCREMENT,
  `category_id` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
  `patterns_json` json NOT NULL,
  `items_analyzed` int DEFAULT '0',
  `learned_at` datetime NOT NULL,
  `updated_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `category_id` (`category_id`),
  KEY `idx_category_id` (`category_id`),
  KEY `idx_updated_at` (`updated_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `chatbot_conversations`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `chatbot_conversations` (
  `id` int NOT NULL AUTO_INCREMENT,
  `account_id` int NOT NULL,
  `conversation_id` varchar(64) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'UUID da conversa (agrupamento)',
  `user_message` text COLLATE utf8mb4_unicode_ci NOT NULL,
  `bot_response` text COLLATE utf8mb4_unicode_ci NOT NULL,
  `context` json DEFAULT NULL COMMENT 'Contexto da mensagem (page, feature, data)',
  `suggested_actions` json DEFAULT NULL COMMENT 'Ações sugeridas extraídas da resposta',
  `tokens_used` int DEFAULT NULL COMMENT 'Tokens consumidos da OpenAI API',
  `processing_time_ms` int DEFAULT NULL COMMENT 'Tempo de processamento em ms',
  `feedback` enum('positive','negative','neutral') COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'Feedback do usuário',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_account_id` (`account_id`),
  KEY `idx_conversation_id` (`conversation_id`),
  KEY `idx_created_at` (`created_at`),
  KEY `idx_account_conversation` (`account_id`,`conversation_id`),
  KEY `idx_feedback` (`feedback`)
) ENGINE=InnoDB AUTO_INCREMENT=240 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Histórico de conversas com o assistente de IA';
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `chatbot_interactions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `chatbot_interactions` (
  `id` int NOT NULL AUTO_INCREMENT,
  `account_id` int NOT NULL,
  `user_id` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `input_text` text COLLATE utf8mb4_unicode_ci NOT NULL,
  `detected_intent` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `intent_confidence` decimal(5,2) DEFAULT NULL,
  `response_text` text COLLATE utf8mb4_unicode_ci,
  `requires_human` tinyint(1) DEFAULT '0',
  `feedback_rating` tinyint DEFAULT NULL,
  `resolved` tinyint(1) DEFAULT '0',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_account` (`account_id`),
  KEY `idx_user` (`user_id`),
  KEY `idx_intent` (`detected_intent`),
  KEY `idx_requires_human` (`requires_human`),
  KEY `idx_created` (`created_at`),
  CONSTRAINT `chatbot_interactions_ibfk_1` FOREIGN KEY (`account_id`) REFERENCES `ml_accounts` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `chatbot_sessions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `chatbot_sessions` (
  `id` int NOT NULL AUTO_INCREMENT,
  `account_id` int NOT NULL,
  `conversation_id` varchar(64) COLLATE utf8mb4_unicode_ci NOT NULL,
  `started_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `ended_at` timestamp NULL DEFAULT NULL,
  `message_count` int DEFAULT '0',
  `total_tokens` int DEFAULT '0',
  `avg_response_time_ms` int DEFAULT NULL,
  `satisfaction_score` decimal(3,2) DEFAULT NULL COMMENT '0.00-1.00',
  PRIMARY KEY (`id`),
  UNIQUE KEY `conversation_id` (`conversation_id`),
  KEY `idx_account_id` (`account_id`),
  KEY `idx_started_at` (`started_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Sessões de conversa agrupadas para analytics';
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `circuit_breaker_log`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `circuit_breaker_log` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `service_name` varchar(100) NOT NULL,
  `event_type` varchar(50) NOT NULL,
  `message` text,
  `created_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_service_time` (`service_name`,`created_at`),
  KEY `idx_event_time` (`event_type`,`created_at`)
) ENGINE=InnoDB AUTO_INCREMENT=236 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `circuit_breaker_state`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `circuit_breaker_state` (
  `service_name` varchar(100) NOT NULL,
  `state` enum('closed','open','half_open') NOT NULL DEFAULT 'closed',
  `failure_count` int unsigned NOT NULL DEFAULT '0',
  `half_open_requests` int unsigned NOT NULL DEFAULT '0',
  `half_open_successes` int unsigned NOT NULL DEFAULT '0',
  `last_failure_at` datetime DEFAULT NULL,
  `last_success_at` datetime DEFAULT NULL,
  `last_failure_reason` varchar(500) DEFAULT NULL,
  `state_changed_at` datetime NOT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`service_name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `clone_alerts`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `clone_alerts` (
  `id` int NOT NULL AUTO_INCREMENT,
  `alert_type` varchar(50) NOT NULL,
  `severity` enum('info','warning','critical') DEFAULT 'warning',
  `message` text NOT NULL,
  `context` json DEFAULT NULL,
  `acknowledged` tinyint(1) DEFAULT '0',
  `acknowledged_by` int DEFAULT NULL,
  `acknowledged_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_type` (`alert_type`),
  KEY `idx_severity` (`severity`),
  KEY `idx_acknowledged` (`acknowledged`),
  KEY `idx_created` (`created_at`)
) ENGINE=InnoDB AUTO_INCREMENT=499 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `clone_duplicate_registry`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `clone_duplicate_registry` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `source_item_id` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
  `target_item_id` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
  `account_id` int unsigned NOT NULL,
  `job_id` varchar(64) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `status` enum('active','inactive') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'active',
  `metadata` json DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_source_account_status` (`source_item_id`,`account_id`,`status`),
  KEY `idx_target_status` (`target_item_id`,`status`),
  KEY `idx_account_created` (`account_id`,`created_at`)
) ENGINE=InnoDB AUTO_INCREMENT=46 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `clone_event_trigger_competitors`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `clone_event_trigger_competitors` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `trigger_id` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL,
  `item_id` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
  `seller_id` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `is_active` tinyint(1) DEFAULT '1',
  `first_seen_at` datetime NOT NULL,
  `inactive_since` datetime DEFAULT NULL,
  `last_price` decimal(15,2) DEFAULT NULL,
  `last_quantity` int unsigned DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_trigger_competitor` (`trigger_id`,`item_id`),
  KEY `idx_trigger_id` (`trigger_id`),
  KEY `idx_is_active` (`is_active`),
  KEY `idx_seller_id` (`seller_id`),
  CONSTRAINT `clone_event_trigger_competitors_ibfk_1` FOREIGN KEY (`trigger_id`) REFERENCES `clone_event_triggers` (`trigger_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `clone_event_trigger_items`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `clone_event_trigger_items` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `trigger_id` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL,
  `item_id` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
  `first_seen_at` datetime NOT NULL,
  `last_check_at` datetime DEFAULT NULL,
  `last_price` decimal(15,2) DEFAULT NULL,
  `has_stock` tinyint(1) DEFAULT '1',
  `metadata` json DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_trigger_item` (`trigger_id`,`item_id`),
  KEY `idx_trigger_id` (`trigger_id`),
  KEY `idx_item_id` (`item_id`),
  KEY `idx_last_check` (`last_check_at`),
  CONSTRAINT `clone_event_trigger_items_ibfk_1` FOREIGN KEY (`trigger_id`) REFERENCES `clone_event_triggers` (`trigger_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `clone_event_trigger_logs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `clone_event_trigger_logs` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `trigger_id` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL,
  `event_type` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
  `item_id` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `event_data` json DEFAULT NULL,
  `action_result` json DEFAULT NULL,
  `created_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_trigger_id` (`trigger_id`),
  KEY `idx_event_type` (`event_type`),
  KEY `idx_created_at` (`created_at`),
  KEY `idx_item_id` (`item_id`),
  CONSTRAINT `clone_event_trigger_logs_ibfk_1` FOREIGN KEY (`trigger_id`) REFERENCES `clone_event_triggers` (`trigger_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `clone_event_triggers`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `clone_event_triggers` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `trigger_id` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL,
  `account_id` int unsigned NOT NULL,
  `name` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `description` text COLLATE utf8mb4_unicode_ci,
  `event_type` enum('new_items','price_drop','stock_available','competitor_out') COLLATE utf8mb4_unicode_ci NOT NULL,
  `source_type` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
  `source_value` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `conditions` json DEFAULT NULL,
  `actions` json DEFAULT NULL,
  `is_active` tinyint(1) DEFAULT '1',
  `check_interval_minutes` int unsigned DEFAULT '30',
  `last_check_at` datetime DEFAULT NULL,
  `total_events_detected` int unsigned DEFAULT '0',
  `total_actions_executed` int unsigned DEFAULT '0',
  `created_at` datetime NOT NULL,
  `updated_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `trigger_id` (`trigger_id`),
  KEY `idx_account_active` (`account_id`,`is_active`),
  KEY `idx_event_type` (`event_type`),
  KEY `idx_last_check` (`last_check_at`),
  KEY `idx_trigger_id` (`trigger_id`)
) ENGINE=InnoDB AUTO_INCREMENT=12 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `clone_export_logs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `clone_export_logs` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `account_id` int NOT NULL,
  `export_scope` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
  `export_format` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL,
  `filename` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `item_count` int NOT NULL DEFAULT '0',
  `size_bytes` bigint NOT NULL DEFAULT '0',
  `filters_json` json DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_clone_export_logs_filename` (`filename`),
  KEY `idx_clone_export_logs_account_created` (`account_id`,`created_at`),
  KEY `idx_clone_export_logs_scope_format` (`export_scope`,`export_format`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `clone_health_logs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `clone_health_logs` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `status` enum('healthy','warning','critical') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'healthy',
  `issues_count` int unsigned NOT NULL DEFAULT '0',
  `check_data` json DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_clone_health_logs_created_at` (`created_at`),
  KEY `idx_clone_health_logs_status` (`status`)
) ENGINE=InnoDB AUTO_INCREMENT=32425 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `clone_health_metrics`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `clone_health_metrics` (
  `id` bigint NOT NULL AUTO_INCREMENT,
  `metric_name` varchar(100) NOT NULL,
  `metric_value` decimal(20,4) NOT NULL,
  `metric_unit` varchar(20) DEFAULT NULL,
  `context` json DEFAULT NULL,
  `recorded_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_metric_name` (`metric_name`),
  KEY `idx_recorded` (`recorded_at`),
  KEY `idx_name_recorded` (`metric_name`,`recorded_at`)
) ENGINE=InnoDB AUTO_INCREMENT=70 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `clone_item_metrics`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `clone_item_metrics` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `item_id` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
  `sales` int unsigned DEFAULT '0',
  `conversion_rate` decimal(5,2) DEFAULT '0.00',
  `revenue` decimal(15,2) DEFAULT '0.00',
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `item_id` (`item_id`),
  KEY `idx_item` (`item_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `clone_metrics`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `clone_metrics` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `job_id` varchar(64) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `account_id` int unsigned DEFAULT NULL,
  `category_id` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `template_slug` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `metric_type` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
  `metric_value` decimal(15,4) NOT NULL,
  `metric_date` date NOT NULL,
  `dimensions` json DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `metric_name` varchar(50) COLLATE utf8mb4_unicode_ci GENERATED ALWAYS AS (`metric_type`) STORED,
  PRIMARY KEY (`id`),
  KEY `idx_job_id` (`job_id`),
  KEY `idx_account` (`account_id`),
  KEY `idx_metric_type` (`metric_type`),
  KEY `idx_metric_date` (`metric_date`)
) ENGINE=InnoDB AUTO_INCREMENT=7 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `clone_post_actions_log`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `clone_post_actions_log` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `clone_job_id` varchar(64) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `cloned_item_id` int unsigned DEFAULT NULL,
  `target_item_id` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
  `action_type` enum('tech_sheet','seo_optimize','pricing_apply','activate') COLLATE utf8mb4_unicode_ci NOT NULL,
  `status` enum('pending','processing','completed','failed','skipped') COLLATE utf8mb4_unicode_ci DEFAULT 'pending',
  `attempts` tinyint unsigned DEFAULT '0',
  `result` json DEFAULT NULL,
  `error_message` text COLLATE utf8mb4_unicode_ci,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `processed_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_status` (`status`),
  KEY `idx_job_id` (`clone_job_id`),
  KEY `idx_target_item` (`target_item_id`),
  KEY `idx_action_type` (`action_type`)
) ENGINE=InnoDB AUTO_INCREMENT=13 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `clone_progress_history`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `clone_progress_history` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `job_id` int unsigned NOT NULL,
  `phase` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'Fase do progresso',
  `progress` decimal(5,2) NOT NULL COMMENT 'Porcentagem de progresso',
  `items_processed` int unsigned NOT NULL COMMENT 'Items processados até aqui',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_job_phase` (`job_id`,`phase`),
  KEY `idx_created` (`created_at`),
  KEY `idx_job_id` (`job_id`),
  CONSTRAINT `clone_progress_history_ibfk_1` FOREIGN KEY (`job_id`) REFERENCES `catalog_clone_jobs` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Histórico de progresso dos jobs para análise de performance';
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `clone_progress_tracking`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `clone_progress_tracking` (
  `job_id` int unsigned NOT NULL,
  `total_items` int unsigned NOT NULL,
  `current_phase` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'validation, preparation, publication, post_actions, completed',
  `phase_progress` decimal(5,2) DEFAULT '0.00' COMMENT 'Progresso da fase atual (0-100)',
  `overall_progress` decimal(5,2) DEFAULT '0.00' COMMENT 'Progresso geral ponderado (0-100)',
  `eta_seconds` int DEFAULT NULL COMMENT 'Tempo estimado restante em segundos',
  `started_at` datetime NOT NULL,
  `completed_at` datetime DEFAULT NULL,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`job_id`),
  KEY `idx_phase` (`current_phase`),
  KEY `idx_started` (`started_at`),
  KEY `idx_job_id` (`job_id`),
  CONSTRAINT `clone_progress_tracking_ibfk_1` FOREIGN KEY (`job_id`) REFERENCES `catalog_clone_jobs` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Tracking de progresso granular dos jobs de clonagem';
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `clone_recommendations_cache`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `clone_recommendations_cache` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `account_id` int unsigned NOT NULL,
  `recommendation_type` enum('seller','product','category','trend') COLLATE utf8mb4_unicode_ci NOT NULL,
  `category_id` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `data` json NOT NULL,
  `expires_at` datetime NOT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_account_type` (`account_id`,`recommendation_type`),
  KEY `idx_expires` (`expires_at`),
  KEY `idx_category` (`category_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `clone_schedule_logs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `clone_schedule_logs` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `schedule_id` int unsigned NOT NULL,
  `account_id` int unsigned NOT NULL,
  `action` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
  `data` json DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_schedule` (`schedule_id`),
  KEY `idx_account` (`account_id`),
  KEY `idx_action` (`action`),
  KEY `idx_created` (`created_at`)
) ENGINE=InnoDB AUTO_INCREMENT=25 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `clone_schedule_runs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `clone_schedule_runs` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `schedule_id` int unsigned NOT NULL,
  `job_id` int unsigned DEFAULT NULL,
  `status` enum('pending','running','completed','failed') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'pending',
  `items_found` int unsigned DEFAULT '0',
  `items_cloned` int unsigned DEFAULT '0',
  `error_message` text COLLATE utf8mb4_unicode_ci,
  `started_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `completed_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_schedule` (`schedule_id`),
  KEY `idx_job` (`job_id`),
  KEY `idx_status` (`status`),
  KEY `idx_started` (`started_at`),
  CONSTRAINT `clone_schedule_runs_ibfk_1` FOREIGN KEY (`schedule_id`) REFERENCES `clone_schedules` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `clone_schedules`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `clone_schedules` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `account_id` int unsigned NOT NULL,
  `name` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `description` text COLLATE utf8mb4_unicode_ci,
  `source_type` enum('seller_id','category_id','search_query','item_list') COLLATE utf8mb4_unicode_ci NOT NULL,
  `source_value` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `frequency` enum('once','hourly','daily','weekly','monthly') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'daily',
  `run_at_hour` tinyint unsigned DEFAULT '3',
  `run_at_minute` tinyint unsigned DEFAULT '0',
  `run_on_days` json DEFAULT NULL COMMENT 'Array de dias da semana [1-7]',
  `trigger_type` enum('scheduled','new_items','price_drop','stock_available') COLLATE utf8mb4_unicode_ci DEFAULT 'scheduled',
  `trigger_conditions` json DEFAULT NULL,
  `template_id` int unsigned DEFAULT NULL,
  `trigger_id` varchar(20) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `max_items_per_run` int unsigned DEFAULT '50',
  `filters` json DEFAULT NULL,
  `seo_level` enum('none','basic','advanced','aggressive') COLLATE utf8mb4_unicode_ci DEFAULT 'basic',
  `is_active` tinyint(1) DEFAULT '1',
  `status` enum('active','paused','running','completed','failed') COLLATE utf8mb4_unicode_ci DEFAULT 'active',
  `next_run_at` datetime DEFAULT NULL,
  `last_run_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_account` (`account_id`),
  KEY `idx_active` (`is_active`),
  KEY `idx_next_run` (`next_run_at`),
  KEY `idx_status` (`status`),
  KEY `template_id` (`template_id`),
  KEY `idx_trigger_id` (`trigger_id`),
  CONSTRAINT `clone_schedules_ibfk_1` FOREIGN KEY (`template_id`) REFERENCES `clone_templates` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=16 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `clone_schedules_backup_old`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `clone_schedules_backup_old` (
  `id` int NOT NULL AUTO_INCREMENT,
  `source_account_id` int NOT NULL,
  `target_account_id` int NOT NULL,
  `source_account_name` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `target_account_name` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `scheduled_datetime` datetime NOT NULL,
  `frequency` enum('once','daily','weekly','monthly') COLLATE utf8mb4_unicode_ci DEFAULT 'once',
  `filters` text COLLATE utf8mb4_unicode_ci,
  `status` enum('active','completed','canceled','error') COLLATE utf8mb4_unicode_ci DEFAULT 'active',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `executed_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_scheduled_datetime` (`scheduled_datetime`),
  KEY `idx_status` (`status`),
  KEY `idx_source_account` (`source_account_id`),
  KEY `idx_target_account` (`target_account_id`)
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `clone_seo_optimizations`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `clone_seo_optimizations` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `job_id` int unsigned NOT NULL,
  `item_id` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'ID do item original (MLB...)',
  `score_before` int unsigned NOT NULL COMMENT 'Score SEO antes da otimização',
  `score_after` int unsigned NOT NULL COMMENT 'Score SEO depois da otimização',
  `changes_applied` json DEFAULT NULL COMMENT 'Detalhes das mudanças aplicadas',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_job` (`job_id`),
  KEY `idx_item` (`item_id`),
  KEY `idx_created` (`created_at`),
  CONSTRAINT `clone_seo_optimizations_ibfk_1` FOREIGN KEY (`job_id`) REFERENCES `catalog_clone_jobs` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Log de otimizações SEO aplicadas durante clonagem';
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `clone_sync_alerts`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `clone_sync_alerts` (
  `id` int NOT NULL AUTO_INCREMENT,
  `account_id` int NOT NULL,
  `item_id` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
  `alert_type` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
  `alert_data` json DEFAULT NULL,
  `status` enum('pending','resolved','dismissed') COLLATE utf8mb4_unicode_ci DEFAULT 'pending',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `resolved_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_account` (`account_id`),
  KEY `idx_item` (`item_id`),
  KEY `idx_status` (`status`),
  KEY `idx_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `clone_sync_logs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `clone_sync_logs` (
  `id` int NOT NULL AUTO_INCREMENT,
  `account_id` int NOT NULL,
  `item_id` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
  `sync_type` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
  `sync_data` json DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_account` (`account_id`),
  KEY `idx_item` (`item_id`),
  KEY `idx_type` (`sync_type`),
  KEY `idx_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `clone_sync_settings`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `clone_sync_settings` (
  `id` int NOT NULL AUTO_INCREMENT,
  `account_id` int NOT NULL,
  `sync_config` json DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_account` (`account_id`),
  KEY `idx_account` (`account_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `clone_templates`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `clone_templates` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `slug` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `description` text COLLATE utf8mb4_unicode_ci,
  `icon` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT 'bi-files',
  `color` varchar(20) COLLATE utf8mb4_unicode_ci DEFAULT 'primary',
  `pricing_type` enum('copy','markup_percent','markdown_percent','fixed','ai_competitive') COLLATE utf8mb4_unicode_ci DEFAULT 'copy',
  `pricing_value` decimal(10,2) DEFAULT NULL,
  `pricing_round_to` decimal(10,2) DEFAULT NULL,
  `stock_type` enum('copy','fixed','percentage','zero') COLLATE utf8mb4_unicode_ci DEFAULT 'copy',
  `stock_value` int DEFAULT NULL,
  `title_prefix` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `title_suffix` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `title_remove_patterns` json DEFAULT NULL,
  `initial_status` enum('active','paused') COLLATE utf8mb4_unicode_ci DEFAULT 'paused',
  `clone_description` tinyint(1) DEFAULT '1',
  `clone_variations` tinyint(1) DEFAULT '1',
  `skip_catalog_items` tinyint(1) DEFAULT '0',
  `skip_non_catalog_items` tinyint(1) DEFAULT '0',
  `post_clone_actions` json DEFAULT NULL,
  `is_system` tinyint(1) DEFAULT '0',
  `is_active` tinyint(1) DEFAULT '1',
  `usage_count` int unsigned DEFAULT '0',
  `created_by_user_id` int unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `slug` (`slug`),
  KEY `idx_slug` (`slug`),
  KEY `idx_is_system` (`is_system`),
  KEY `idx_is_active` (`is_active`)
) ENGINE=InnoDB AUTO_INCREMENT=13 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `clone_trend_cache`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `clone_trend_cache` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `account_id` int unsigned NOT NULL,
  `chart_type` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
  `cache_key` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `chart_data` json NOT NULL,
  `expires_at` datetime NOT NULL,
  `created_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_account_chart_key` (`account_id`,`chart_type`,`cache_key`),
  KEY `idx_expires` (`expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `cloned_items`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `cloned_items` (
  `id` int NOT NULL AUTO_INCREMENT,
  `job_id` int DEFAULT NULL,
  `source_account_id` int NOT NULL,
  `source_item_id` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
  `target_account_id` int NOT NULL,
  `target_item_id` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `catalog_product_id` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `pricing_strategy` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `original_price` decimal(12,2) DEFAULT NULL,
  `final_price` decimal(12,2) DEFAULT NULL,
  `processing_time_ms` int DEFAULT NULL,
  `retry_count` tinyint DEFAULT '0',
  `status` enum('created','skipped_duplicate','error') COLLATE utf8mb4_unicode_ci NOT NULL,
  `is_active` tinyint(1) DEFAULT '1',
  `error_message` text COLLATE utf8mb4_unicode_ci,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `is_catalog` tinyint(1) NOT NULL DEFAULT '1' COMMENT 'Whether cloned item is catalog',
  `brand` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'Source item brand',
  `source_snapshot` json DEFAULT NULL COMMENT 'Snapshot of source item at clone time',
  `clone_job_id` varchar(64) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'Reference to batch job if applicable',
  PRIMARY KEY (`id`),
  KEY `idx_source` (`source_account_id`,`source_item_id`),
  KEY `idx_target` (`target_account_id`),
  KEY `idx_catalog` (`catalog_product_id`),
  KEY `idx_job_id` (`job_id`),
  KEY `idx_clone_job` (`clone_job_id`),
  KEY `idx_is_catalog` (`is_catalog`)
) ENGINE=InnoDB AUTO_INCREMENT=48 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `competitor_alert_history`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `competitor_alert_history` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tracking_id` int unsigned NOT NULL,
  `competitor_price` decimal(10,2) DEFAULT NULL,
  `competitor_stock` int DEFAULT NULL,
  `my_price` decimal(10,2) DEFAULT NULL,
  `price_diff` decimal(10,2) DEFAULT NULL,
  `price_diff_percent` decimal(5,2) DEFAULT NULL,
  `checked_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_tracking` (`tracking_id`),
  KEY `idx_checked` (`checked_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `competitor_alerts`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `competitor_alerts` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tracking_id` int unsigned NOT NULL,
  `type` enum('price_drop','price_increase','out_of_stock','back_in_stock','new_listing') COLLATE utf8mb4_unicode_ci NOT NULL,
  `severity` enum('info','warning','critical','success') COLLATE utf8mb4_unicode_ci DEFAULT 'info',
  `message` text COLLATE utf8mb4_unicode_ci,
  `old_value` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `new_value` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `is_read` tinyint(1) DEFAULT '0',
  `read_at` datetime DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_tracking` (`tracking_id`),
  KEY `idx_type` (`type`),
  KEY `idx_severity` (`severity`),
  KEY `idx_is_read` (`is_read`),
  KEY `idx_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `competitor_history`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `competitor_history` (
  `id` int NOT NULL AUTO_INCREMENT,
  `watchlist_id` int NOT NULL,
  `field` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
  `old_value` text COLLATE utf8mb4_unicode_ci,
  `new_value` text COLLATE utf8mb4_unicode_ci,
  `change_type` varchar(20) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_watchlist` (`watchlist_id`),
  KEY `idx_field` (`field`),
  KEY `idx_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `competitor_intelligence`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `competitor_intelligence` (
  `id` int NOT NULL AUTO_INCREMENT,
  `account_id` varchar(100) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `competitor_id` varchar(100) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `competitor_name` varchar(200) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `seller_id` varchar(100) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `tracking_start` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `last_update` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `historical_patterns` json DEFAULT NULL,
  `prediction_confidence` decimal(5,4) DEFAULT NULL,
  `threat_level` enum('low','medium','high','critical') COLLATE utf8mb4_general_ci DEFAULT 'medium',
  `counter_strategies` json DEFAULT NULL,
  `intelligence_quality` decimal(5,4) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_account_competitor` (`account_id`,`competitor_id`),
  KEY `idx_threat_level` (`threat_level`),
  KEY `idx_last_update` (`last_update`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `competitor_items`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `competitor_items` (
  `id` int NOT NULL AUTO_INCREMENT,
  `account_id` int NOT NULL,
  `ml_item_id` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
  `seller_id` bigint NOT NULL,
  `title` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `price` decimal(10,2) DEFAULT NULL,
  `permalink` text COLLATE utf8mb4_unicode_ci,
  `status` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` datetime DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_item` (`account_id`,`ml_item_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `competitor_logs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `competitor_logs` (
  `id` int NOT NULL AUTO_INCREMENT,
  `account_id` int NOT NULL,
  `ml_item_id` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
  `change_type` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `old_value` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `new_value` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `change_percent` decimal(5,2) DEFAULT NULL,
  `created_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `competitor_price_history`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `competitor_price_history` (
  `id` int NOT NULL AUTO_INCREMENT,
  `competitor_item_id` int NOT NULL,
  `price` decimal(10,2) NOT NULL,
  `min_price` decimal(10,2) DEFAULT NULL,
  `max_price` decimal(10,2) DEFAULT NULL,
  `last_price` decimal(10,2) DEFAULT NULL,
  `stock` int DEFAULT NULL,
  `snapshot_count` int DEFAULT '1',
  `recorded_at` date NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_history` (`competitor_item_id`,`recorded_at`),
  KEY `idx_recorded` (`recorded_at`),
  CONSTRAINT `competitor_price_history_ibfk_1` FOREIGN KEY (`competitor_item_id`) REFERENCES `competitor_items` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `competitor_prices`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `competitor_prices` (
  `id` int NOT NULL AUTO_INCREMENT,
  `account_id` int NOT NULL,
  `item_id` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
  `competitor_item_id` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
  `competitor_seller_id` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `competitor_price` decimal(12,2) NOT NULL,
  `competitor_reputation` int DEFAULT NULL,
  `competitor_sold_quantity` int DEFAULT NULL,
  `our_price` decimal(12,2) DEFAULT NULL,
  `price_difference` decimal(12,2) DEFAULT NULL,
  `scanned_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_account` (`account_id`),
  KEY `idx_item` (`item_id`),
  KEY `idx_scanned` (`scanned_at`),
  CONSTRAINT `competitor_prices_ibfk_1` FOREIGN KEY (`account_id`) REFERENCES `ml_accounts` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `competitor_prices_cache`;
/*!50001 DROP VIEW IF EXISTS `competitor_prices_cache`*/;
SET @saved_cs_client     = @@character_set_client;
/*!50503 SET character_set_client = utf8mb4 */;
/*!50001 CREATE VIEW `competitor_prices_cache` AS SELECT 
 1 AS `id`,
 1 AS `account_id`,
 1 AS `item_id`,
 1 AS `category_id`,
 1 AS `preco_minimo`,
 1 AS `preco_maximo`,
 1 AS `preco_medio`,
 1 AS `preco_mediano`,
 1 AS `qtd_concorrentes`,
 1 AS `top_concorrentes`,
 1 AS `nossa_posicao_preco`,
 1 AS `percentil_preco`,
 1 AS `tendencia_7d`,
 1 AS `tendencia_30d`,
 1 AS `atualizado_em`,
 1 AS `expira_em`*/;
SET character_set_client = @saved_cs_client;
DROP TABLE IF EXISTS `competitor_pricing_cache`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `competitor_pricing_cache` (
  `id` bigint NOT NULL AUTO_INCREMENT,
  `account_id` int NOT NULL,
  `item_id` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
  `category_id` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
  `preco_minimo` decimal(12,2) DEFAULT NULL,
  `preco_maximo` decimal(12,2) DEFAULT NULL,
  `preco_medio` decimal(12,2) DEFAULT NULL,
  `preco_mediano` decimal(12,2) DEFAULT NULL,
  `qtd_concorrentes` int DEFAULT '0',
  `top_concorrentes` json DEFAULT NULL COMMENT '[{id, titulo, preco, vendedor, reputacao}]',
  `nossa_posicao_preco` int DEFAULT NULL COMMENT 'Ranking de preço (1 = mais barato)',
  `percentil_preco` decimal(5,2) DEFAULT NULL COMMENT 'Percentil de preço (0-100)',
  `tendencia_7d` decimal(6,2) DEFAULT NULL COMMENT 'Variação média de preço 7 dias %',
  `tendencia_30d` decimal(6,2) DEFAULT NULL COMMENT 'Variação média de preço 30 dias %',
  `atualizado_em` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `expira_em` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_account_item` (`account_id`,`item_id`),
  KEY `idx_category` (`category_id`),
  KEY `idx_expira` (`expira_em`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `competitor_sellers`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `competitor_sellers` (
  `id` int NOT NULL AUTO_INCREMENT,
  `account_id` int NOT NULL,
  `ml_seller_id` bigint NOT NULL,
  `nickname` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_seller` (`account_id`,`ml_seller_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `competitor_tracking`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `competitor_tracking` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `account_id` int unsigned NOT NULL,
  `my_item_id` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
  `competitor_item_id` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
  `competitor_price` decimal(10,2) DEFAULT '0.00',
  `competitor_stock` int DEFAULT '0',
  `competitor_title` varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `competitor_seller_id` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `competitor_reputation` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `my_price` decimal(10,2) DEFAULT '0.00',
  `alert_price_drop` tinyint(1) DEFAULT '1',
  `alert_price_increase` tinyint(1) DEFAULT '1',
  `alert_stock_change` tinyint(1) DEFAULT '1',
  `is_active` tinyint(1) DEFAULT '1',
  `last_checked` datetime DEFAULT NULL,
  `check_frequency_minutes` int DEFAULT '60',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_tracking` (`my_item_id`,`competitor_item_id`,`account_id`),
  KEY `idx_account` (`account_id`),
  KEY `idx_my_item` (`my_item_id`),
  KEY `idx_competitor_item` (`competitor_item_id`),
  KEY `idx_active` (`is_active`),
  KEY `idx_last_checked` (`last_checked`),
  KEY `idx_tracking_account_last_checked` (`account_id`,`last_checked`)
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `competitor_watchlist`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `competitor_watchlist` (
  `id` int NOT NULL AUTO_INCREMENT,
  `account_id` int NOT NULL,
  `competitor_item_id` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
  `competitor_seller_id` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `nickname` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `title` text COLLATE utf8mb4_unicode_ci,
  `price` decimal(10,2) DEFAULT NULL,
  `sold_quantity` int DEFAULT '0',
  `available_quantity` int DEFAULT '0',
  `listing_type` varchar(20) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `seo_score` int DEFAULT NULL,
  `title_length` int DEFAULT NULL,
  `pictures_count` int DEFAULT '0',
  `attributes_filled` int DEFAULT '0',
  `free_shipping` tinyint(1) DEFAULT '0',
  `shipping_mode` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `status` varchar(20) COLLATE utf8mb4_unicode_ci DEFAULT 'active',
  `category_id` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `tags` text COLLATE utf8mb4_unicode_ci,
  `notes` text COLLATE utf8mb4_unicode_ci,
  `alert_on_changes` tinyint(1) DEFAULT '1',
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `last_checked_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_account_item` (`account_id`,`competitor_item_id`),
  KEY `idx_account` (`account_id`),
  KEY `idx_competitor_item` (`competitor_item_id`),
  KEY `idx_seller` (`competitor_seller_id`),
  KEY `idx_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `cross_account_learning`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `cross_account_learning` (
  `id` int NOT NULL AUTO_INCREMENT,
  `pattern_hash` varchar(64) COLLATE utf8mb4_general_ci NOT NULL,
  `pattern_type` varchar(100) COLLATE utf8mb4_general_ci NOT NULL,
  `category_id` varchar(50) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `market_conditions` json DEFAULT NULL,
  `success_patterns` json DEFAULT NULL,
  `failure_patterns` json DEFAULT NULL,
  `contributing_accounts` json DEFAULT NULL,
  `total_applications` int DEFAULT '0',
  `success_applications` int DEFAULT '0',
  `average_success_rate` decimal(5,4) DEFAULT NULL,
  `confidence_score` decimal(5,4) DEFAULT NULL,
  `generalizability_score` decimal(5,4) DEFAULT NULL,
  `last_updated` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_pattern` (`pattern_hash`,`pattern_type`,`category_id`),
  KEY `idx_pattern_type` (`pattern_type`),
  KEY `idx_category` (`category_id`),
  KEY `idx_success_rate` (`average_success_rate` DESC),
  KEY `idx_confidence` (`confidence_score` DESC)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `curriculum_progress`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `curriculum_progress` (
  `id` int NOT NULL AUTO_INCREMENT,
  `account_id` varchar(100) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `level_key` varchar(50) COLLATE utf8mb4_general_ci NOT NULL,
  `level_name` varchar(200) COLLATE utf8mb4_general_ci NOT NULL,
  `complexity` decimal(3,2) NOT NULL,
  `episodes_completed` int DEFAULT '0',
  `successes` int DEFAULT '0',
  `success_rate` decimal(5,4) DEFAULT '0.0000',
  `mastered` tinyint(1) DEFAULT '0',
  `mastered_at` timestamp NULL DEFAULT NULL,
  `current_objectives` json DEFAULT NULL,
  `progress_metrics` json DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_account_level` (`account_id`,`level_key`),
  KEY `idx_account_mastered` (`account_id`,`mastered`),
  KEY `idx_success_rate` (`success_rate`)
) ENGINE=InnoDB AUTO_INCREMENT=13 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `ean_assignments`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `ean_assignments` (
  `id` int NOT NULL AUTO_INCREMENT,
  `ean_id` int NOT NULL,
  `account_id` int NOT NULL,
  `purchase_id` int DEFAULT NULL,
  `ml_item_id` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `product_title` varchar(200) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `product_sku` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `category_id` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `assigned_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_ean` (`ean_id`),
  KEY `idx_account` (`account_id`),
  KEY `idx_ml_item` (`ml_item_id`),
  KEY `idx_purchase_id` (`purchase_id`),
  CONSTRAINT `ean_assignments_ibfk_1` FOREIGN KEY (`ean_id`) REFERENCES `ean_inventory` (`id`) ON DELETE CASCADE,
  CONSTRAINT `ean_assignments_ibfk_2` FOREIGN KEY (`account_id`) REFERENCES `ml_accounts` (`id`) ON DELETE CASCADE,
  CONSTRAINT `ean_assignments_ibfk_3` FOREIGN KEY (`purchase_id`) REFERENCES `ean_purchases` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=32 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `ean_balances`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `ean_balances` (
  `id` int NOT NULL AUTO_INCREMENT,
  `account_id` int NOT NULL,
  `total_purchased` int DEFAULT '0',
  `total_used` int DEFAULT '0',
  `available` int DEFAULT '0',
  `last_purchase_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `account_id` (`account_id`),
  KEY `idx_account_id` (`account_id`),
  CONSTRAINT `ean_balances_ibfk_1` FOREIGN KEY (`account_id`) REFERENCES `ml_accounts` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=16387 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `ean_inventory`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `ean_inventory` (
  `id` int NOT NULL AUTO_INCREMENT,
  `ean` varchar(13) COLLATE utf8mb4_unicode_ci NOT NULL,
  `status` enum('available','reserved','sold') COLLATE utf8mb4_unicode_ci DEFAULT 'available',
  `purchase_batch` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `cost` decimal(10,2) DEFAULT '0.00',
  `supplier` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `notes` text COLLATE utf8mb4_unicode_ci,
  `reserved_at` timestamp NULL DEFAULT NULL,
  `sold_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `ean` (`ean`),
  KEY `idx_status` (`status`),
  KEY `idx_batch` (`purchase_batch`)
) ENGINE=InnoDB AUTO_INCREMENT=1011 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `ean_packages`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `ean_packages` (
  `id` int NOT NULL AUTO_INCREMENT,
  `name` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
  `slug` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
  `quantity` int NOT NULL,
  `price` decimal(10,2) NOT NULL,
  `price_per_ean` decimal(10,2) NOT NULL,
  `discount_percent` int DEFAULT '0',
  `description` text COLLATE utf8mb4_unicode_ci,
  `badge` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `is_featured` tinyint(1) DEFAULT '0',
  `is_active` tinyint(1) DEFAULT '1',
  `sort_order` int DEFAULT '0',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `slug` (`slug`)
) ENGINE=InnoDB AUTO_INCREMENT=22 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `ean_purchases`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `ean_purchases` (
  `id` int NOT NULL AUTO_INCREMENT,
  `account_id` int NOT NULL,
  `package_id` int DEFAULT NULL,
  `quantity` int NOT NULL,
  `unit_price` decimal(10,2) NOT NULL,
  `total_amount` decimal(10,2) NOT NULL,
  `discount_applied` decimal(10,2) DEFAULT '0.00',
  `payment_method` enum('pix','credit_card','boleto','mercado_pago') COLLATE utf8mb4_unicode_ci DEFAULT 'pix',
  `payment_status` enum('pending','processing','paid','failed','refunded','cancelled') COLLATE utf8mb4_unicode_ci DEFAULT 'pending',
  `payment_id` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `payment_external_id` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `payment_url` text COLLATE utf8mb4_unicode_ci,
  `payment_qr_code` text COLLATE utf8mb4_unicode_ci,
  `payment_qr_code_base64` longtext COLLATE utf8mb4_unicode_ci,
  `payment_expires_at` timestamp NULL DEFAULT NULL,
  `paid_at` timestamp NULL DEFAULT NULL,
  `notes` text COLLATE utf8mb4_unicode_ci,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_account` (`account_id`),
  KEY `idx_status` (`payment_status`),
  KEY `idx_payment_id` (`payment_id`),
  KEY `idx_package_id` (`package_id`),
  CONSTRAINT `ean_purchases_ibfk_1` FOREIGN KEY (`account_id`) REFERENCES `ml_accounts` (`id`) ON DELETE CASCADE,
  CONSTRAINT `ean_purchases_ibfk_2` FOREIGN KEY (`package_id`) REFERENCES `ean_packages` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `ean_settings`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `ean_settings` (
  `id` int NOT NULL AUTO_INCREMENT,
  `setting_key` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `setting_value` text COLLATE utf8mb4_unicode_ci,
  `setting_type` enum('string','int','float','boolean','json') COLLATE utf8mb4_unicode_ci DEFAULT 'string',
  `description` text COLLATE utf8mb4_unicode_ci,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `setting_key` (`setting_key`)
) ENGINE=InnoDB AUTO_INCREMENT=212987 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `ean_transactions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `ean_transactions` (
  `id` int NOT NULL AUTO_INCREMENT,
  `account_id` int NOT NULL,
  `type` enum('credit','debit','refund','adjustment') COLLATE utf8mb4_unicode_ci NOT NULL,
  `quantity` int NOT NULL,
  `balance_before` int NOT NULL,
  `balance_after` int NOT NULL,
  `reference_type` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `reference_id` int DEFAULT NULL,
  `description` text COLLATE utf8mb4_unicode_ci,
  `created_by` int DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_account` (`account_id`),
  KEY `idx_type` (`type`),
  CONSTRAINT `ean_transactions_ibfk_1` FOREIGN KEY (`account_id`) REFERENCES `ml_accounts` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `email_logs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `email_logs` (
  `id` int NOT NULL AUTO_INCREMENT,
  `recipient` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `subject` varchar(500) COLLATE utf8mb4_unicode_ci NOT NULL,
  `status` enum('success','failed','error') COLLATE utf8mb4_unicode_ci NOT NULL,
  `error_message` text COLLATE utf8mb4_unicode_ci,
  `sent_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_recipient` (`recipient`),
  KEY `idx_status` (`status`),
  KEY `idx_sent_at` (`sent_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `error_monitoring`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `error_monitoring` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `error_type` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `error_message` text COLLATE utf8mb4_unicode_ci NOT NULL,
  `file` varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `line` int DEFAULT NULL,
  `trace` text COLLATE utf8mb4_unicode_ci COMMENT 'JSON stack trace',
  `context` text COLLATE utf8mb4_unicode_ci COMMENT 'JSON context data',
  `user_id` int unsigned DEFAULT NULL,
  `account_id` int unsigned DEFAULT NULL,
  `url` varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `ip_address` varchar(45) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `user_agent` text COLLATE utf8mb4_unicode_ci,
  `severity` enum('critical','error','warning','notice') COLLATE utf8mb4_unicode_ci DEFAULT 'error',
  `resolved` tinyint(1) DEFAULT '0',
  `resolved_at` datetime DEFAULT NULL,
  `resolved_by` int unsigned DEFAULT NULL,
  `created_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_created_at` (`created_at`),
  KEY `idx_severity` (`severity`),
  KEY `idx_error_type` (`error_type`),
  KEY `idx_file_line` (`file`,`line`),
  KEY `idx_account_id` (`account_id`),
  KEY `idx_resolved` (`resolved`),
  KEY `idx_error_monitor_account_created` (`account_id`,`created_at`)
) ENGINE=InnoDB AUTO_INCREMENT=16713 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `feature_flags`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `feature_flags` (
  `id` int NOT NULL AUTO_INCREMENT,
  `flag_name` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `is_enabled` tinyint(1) DEFAULT '1',
  `description` text COLLATE utf8mb4_unicode_ci,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `flag_name` (`flag_name`),
  KEY `idx_flag_name` (`flag_name`)
) ENGINE=InnoDB AUTO_INCREMENT=1500 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `financial_settlements`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `financial_settlements` (
  `id` bigint NOT NULL AUTO_INCREMENT,
  `account_id` int NOT NULL DEFAULT '1',
  `ml_record_id` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `order_id` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `pack_id` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `external_reference` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `date_released` datetime NOT NULL,
  `description` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `type` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
  `gross_amount` decimal(10,2) NOT NULL,
  `net_amount` decimal(10,2) NOT NULL,
  `fee_amount` decimal(10,2) DEFAULT '0.00',
  `balance` decimal(10,2) DEFAULT NULL,
  `status` enum('PENDING','CONCILIATED','MISMATCH','Ignored') COLLATE utf8mb4_unicode_ci DEFAULT 'PENDING',
  `reconciled_at` datetime DEFAULT NULL,
  `reconciliation_notes` text COLLATE utf8mb4_unicode_ci,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `ml_record_id` (`ml_record_id`),
  KEY `idx_order` (`order_id`),
  KEY `idx_date` (`date_released`),
  KEY `idx_status` (`status`)
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `fulfillment_inbound_shipments`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `fulfillment_inbound_shipments` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `account_id` int NOT NULL,
  `shipment_id` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `tracking_number` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `warehouse_id` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `status` varchar(30) COLLATE utf8mb4_unicode_ci DEFAULT 'pending',
  `items_data` json DEFAULT NULL,
  `estimated_delivery` date DEFAULT NULL,
  `actual_delivery` date DEFAULT NULL,
  `notes` text COLLATE utf8mb4_unicode_ci,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `shipment_id` (`shipment_id`),
  KEY `idx_account_id` (`account_id`),
  KEY `idx_shipment_id` (`shipment_id`),
  KEY `idx_status` (`status`),
  KEY `idx_warehouse_id` (`warehouse_id`),
  KEY `idx_created_at` (`created_at`),
  CONSTRAINT `fulfillment_inbound_shipments_ibfk_1` FOREIGN KEY (`account_id`) REFERENCES `ml_accounts` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `governance_diagnostic_history`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `governance_diagnostic_history` (
  `id` int NOT NULL AUTO_INCREMENT,
  `account_id` int NOT NULL COMMENT 'FK to mercadolivre_auth.id',
  `account_status` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'active/inactive/mixed',
  `total_items` int DEFAULT '0',
  `healthy_items` int DEFAULT '0',
  `problem_items` int DEFAULT '0',
  `critical_actions` int DEFAULT '0' COMMENT 'Number of critical priority actions',
  `top_causes` json DEFAULT NULL COMMENT 'Top health issue causes',
  `executive_summary` json DEFAULT NULL COMMENT 'Key metrics summary',
  `full_result` json DEFAULT NULL COMMENT 'Complete diagnostic result',
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_account_id` (`account_id`),
  KEY `idx_created_at` (`created_at`),
  KEY `idx_account_created` (`account_id`,`created_at`),
  KEY `idx_critical` (`account_id`,`critical_actions`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Governance diagnostic history for trend analysis';
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `inventory_alerts`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `inventory_alerts` (
  `id` int NOT NULL AUTO_INCREMENT,
  `item_id` varchar(50) COLLATE utf8mb4_general_ci NOT NULL,
  `title` varchar(255) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `current_stock` int DEFAULT NULL,
  `urgency` varchar(20) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `recommended_qty` int DEFAULT NULL,
  `acknowledged` tinyint(1) DEFAULT '0',
  `created_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `item_id` (`item_id`),
  KEY `idx_urgency` (`urgency`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `inventory_movements`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `inventory_movements` (
  `id` int NOT NULL AUTO_INCREMENT,
  `account_id` int NOT NULL,
  `sku` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `type` varchar(30) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'sale, purchase, adjustment, transfer',
  `quantity` int NOT NULL,
  `origin` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `reference_id` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `notes` text COLLATE utf8mb4_unicode_ci,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_account` (`account_id`),
  KEY `idx_sku` (`sku`),
  KEY `idx_type` (`type`),
  KEY `idx_created` (`created_at`),
  CONSTRAINT `inventory_movements_ibfk_1` FOREIGN KEY (`account_id`) REFERENCES `ml_accounts` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `inventory_origins`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `inventory_origins` (
  `id` int NOT NULL AUTO_INCREMENT,
  `account_id` int NOT NULL,
  `sku` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `origin` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'warehouse, dropshipping, store',
  `quantity` int DEFAULT '0',
  `reserved` int DEFAULT '0',
  `available` int GENERATED ALWAYS AS ((`quantity` - `reserved`)) STORED,
  `location` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_origin` (`account_id`,`sku`,`origin`),
  KEY `idx_account` (`account_id`),
  KEY `idx_sku` (`sku`),
  KEY `idx_origin` (`origin`),
  KEY `idx_available` (`available`),
  CONSTRAINT `inventory_origins_ibfk_1` FOREIGN KEY (`account_id`) REFERENCES `ml_accounts` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `inventory_reservations`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `inventory_reservations` (
  `id` int NOT NULL AUTO_INCREMENT,
  `account_id` int NOT NULL,
  `reservation_id` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
  `sku` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `quantity` int NOT NULL,
  `order_id` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `status` varchar(20) COLLATE utf8mb4_unicode_ci DEFAULT 'active' COMMENT 'active, released, expired',
  `expires_at` timestamp NOT NULL,
  `metadata` json DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_reservation` (`reservation_id`),
  KEY `idx_account` (`account_id`),
  KEY `idx_sku` (`sku`),
  KEY `idx_status` (`status`),
  KEY `idx_expires` (`expires_at`),
  KEY `idx_order` (`order_id`),
  CONSTRAINT `inventory_reservations_ibfk_1` FOREIGN KEY (`account_id`) REFERENCES `ml_accounts` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `item_metrics`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `item_metrics` (
  `id` int NOT NULL AUTO_INCREMENT,
  `account_id` int NOT NULL,
  `item_id` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
  `title` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `price` decimal(12,2) DEFAULT NULL,
  `visits` int DEFAULT '0',
  `sales` int DEFAULT '0',
  `conversion_rate` decimal(5,2) DEFAULT '0.00',
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_item` (`account_id`,`item_id`),
  KEY `idx_account` (`account_id`),
  KEY `idx_visits` (`visits`),
  KEY `idx_conversion` (`conversion_rate`),
  CONSTRAINT `item_metrics_ibfk_1` FOREIGN KEY (`account_id`) REFERENCES `ml_accounts` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `item_metrics_history`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `item_metrics_history` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `account_id` int NOT NULL,
  `item_id` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
  `date` date NOT NULL,
  `visits` int DEFAULT '0',
  `sold_quantity` int DEFAULT '0',
  `conversion_rate` decimal(5,2) DEFAULT '0.00',
  `health_score` int DEFAULT '0',
  `price` decimal(15,2) DEFAULT '0.00',
  `data` json DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_account_item_date` (`account_id`,`item_id`,`date`),
  KEY `idx_account_id` (`account_id`),
  KEY `idx_item_id` (`item_id`),
  KEY `idx_date` (`date`),
  KEY `idx_health_score` (`health_score`),
  CONSTRAINT `item_metrics_history_ibfk_1` FOREIGN KEY (`account_id`) REFERENCES `ml_accounts` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `items`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `items` (
  `id` int NOT NULL AUTO_INCREMENT,
  `ml_item_id` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
  `account_id` int NOT NULL,
  `title` varchar(500) COLLATE utf8mb4_unicode_ci NOT NULL,
  `category_id` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `category_name` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `price` decimal(10,2) DEFAULT NULL,
  `cost_price` decimal(10,2) DEFAULT NULL,
  `tax_rate` decimal(5,2) DEFAULT '0.00',
  `pricing_strategy` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `sku` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `thumbnail` varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `permalink` varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `min_price` decimal(10,2) DEFAULT NULL,
  `max_price` decimal(10,2) DEFAULT NULL,
  `auto_reprice` tinyint(1) DEFAULT '0',
  `currency_id` varchar(10) COLLATE utf8mb4_unicode_ci DEFAULT 'BRL',
  `available_quantity` int DEFAULT '0',
  `sold_quantity` int NOT NULL DEFAULT '0',
  `status` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT 'unknown',
  `condition_type` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `catalog_product_id` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `data` json DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `auto_negotiate` tinyint(1) DEFAULT '0',
  `visits` int DEFAULT '0',
  PRIMARY KEY (`id`),
  UNIQUE KEY `ml_item_id` (`ml_item_id`),
  KEY `idx_account_id` (`account_id`),
  KEY `idx_category_id` (`category_id`),
  KEY `idx_status` (`status`),
  KEY `idx_catalog_product_id` (`catalog_product_id`),
  KEY `idx_sku` (`sku`),
  KEY `idx_auto_reprice` (`auto_reprice`),
  KEY `idx_sold_quantity` (`sold_quantity`),
  CONSTRAINT `items_ibfk_1` FOREIGN KEY (`account_id`) REFERENCES `ml_accounts` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=55509 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `jobs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `jobs` (
  `id` int NOT NULL AUTO_INCREMENT,
  `type` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `payload` json NOT NULL,
  `status` enum('pending','processing','completed','failed') COLLATE utf8mb4_unicode_ci DEFAULT 'pending',
  `attempts` int DEFAULT '0',
  `max_attempts` int DEFAULT '3',
  `error_message` text COLLATE utf8mb4_unicode_ci,
  `scheduled_at` datetime DEFAULT NULL,
  `next_attempt_at` datetime DEFAULT NULL,
  `claim_token` varchar(64) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `claimed_by` varchar(128) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `claimed_at` datetime DEFAULT NULL,
  `started_at` datetime DEFAULT NULL,
  `completed_at` datetime DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `result` json DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_status` (`status`),
  KEY `idx_type` (`type`),
  KEY `idx_scheduled_at` (`scheduled_at`),
  KEY `idx_claim_token` (`claim_token`),
  KEY `idx_claimed_at` (`claimed_at`),
  KEY `idx_next_attempt_at` (`next_attempt_at`)
) ENGINE=InnoDB AUTO_INCREMENT=81432 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `keyword_classifications`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `keyword_classifications` (
  `id` int NOT NULL AUTO_INCREMENT,
  `keyword_hash` varchar(32) COLLATE utf8mb4_unicode_ci NOT NULL,
  `keyword` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `category_context` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `type` enum('CORE','BRANDED','SUPPORT','MODIFIER','LONG_TAIL') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'CORE',
  `weight` decimal(3,2) NOT NULL DEFAULT '0.50',
  `confidence` decimal(3,2) NOT NULL DEFAULT '0.50',
  `reason` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_keyword_hash` (`keyword_hash`),
  KEY `idx_keyword` (`keyword`(100)),
  KEY `idx_type` (`type`),
  KEY `idx_confidence` (`confidence`),
  KEY `idx_created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `keyword_trends`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `keyword_trends` (
  `id` int NOT NULL AUTO_INCREMENT,
  `keyword_hash` varchar(32) COLLATE utf8mb4_unicode_ci NOT NULL,
  `keyword` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `total_results` int DEFAULT '0',
  `avg_price` decimal(10,2) DEFAULT '0.00',
  `trend_score` decimal(3,2) DEFAULT '0.50',
  `trend_direction` enum('up','down','stable','unknown') COLLATE utf8mb4_unicode_ci DEFAULT 'unknown',
  `recorded_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_keyword_hash` (`keyword_hash`),
  KEY `idx_keyword` (`keyword`(100)),
  KEY `idx_trend_score` (`trend_score`),
  KEY `idx_recorded_at` (`recorded_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `learning_experiences`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `learning_experiences` (
  `id` int NOT NULL AUTO_INCREMENT,
  `account_id` varchar(100) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `state_hash` varchar(64) COLLATE utf8mb4_general_ci NOT NULL,
  `action` varchar(100) COLLATE utf8mb4_general_ci NOT NULL,
  `reward` decimal(10,6) NOT NULL,
  `new_state_hash` varchar(64) COLLATE utf8mb4_general_ci NOT NULL,
  `execution_data` json DEFAULT NULL,
  `episode_number` int DEFAULT NULL,
  `step_number` int DEFAULT NULL,
  `done` tinyint(1) DEFAULT '0',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_account_state` (`account_id`,`state_hash`),
  KEY `idx_created_at` (`created_at`),
  KEY `idx_episode_step` (`episode_number`,`step_number`),
  KEY `idx_learning_experiences_composite` (`account_id`,`state_hash`,`action`,`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET collation_connection  = utf8mb4_0900_ai_ci */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION' */ ;
DELIMITER ;;
/*!50003 CREATE*/  /*!50003 TRIGGER `archive_old_experiences` AFTER INSERT ON `learning_experiences` FOR EACH ROW BEGIN

    DELETE FROM learning_experiences 
    WHERE created_at < DATE_SUB(NOW(), INTERVAL 6 MONTH) 
    AND account_id = NEW.account_id 
    LIMIT 1000;
END */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
DROP TABLE IF EXISTS `learning_insights`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `learning_insights` (
  `id` int NOT NULL AUTO_INCREMENT,
  `cycle_id` varchar(100) COLLATE utf8mb4_general_ci NOT NULL,
  `account_id` varchar(100) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `insights_data` json NOT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_cycle_id` (`cycle_id`),
  KEY `idx_account_id` (`account_id`),
  KEY `idx_created_at` (`created_at`),
  CONSTRAINT `learning_insights_ibfk_1` FOREIGN KEY (`cycle_id`) REFERENCES `autopilot_cycles` (`cycle_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `learning_models`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `learning_models` (
  `id` int NOT NULL AUTO_INCREMENT,
  `account_id` int NOT NULL,
  `model_type` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
  `model_data` json DEFAULT NULL,
  `accuracy` decimal(5,4) DEFAULT '0.5000',
  `trained_at` datetime DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_account_type` (`account_id`,`model_type`),
  KEY `idx_accuracy` (`accuracy` DESC),
  KEY `idx_trained` (`trained_at` DESC)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `learning_outcomes`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `learning_outcomes` (
  `id` int NOT NULL AUTO_INCREMENT,
  `account_id` int NOT NULL,
  `learning_type` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
  `outcome_data` json DEFAULT NULL,
  `success_score` decimal(5,4) DEFAULT '0.5000',
  `processed` tinyint(1) DEFAULT '0',
  `processed_at` datetime DEFAULT NULL,
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_account_type` (`account_id`,`learning_type`),
  KEY `idx_processed` (`processed`,`learning_type`),
  KEY `idx_success` (`success_score` DESC)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `learning_performance`;
/*!50001 DROP VIEW IF EXISTS `learning_performance`*/;
SET @saved_cs_client     = @@character_set_client;
/*!50503 SET character_set_client = utf8mb4 */;
/*!50001 CREATE VIEW `learning_performance` AS SELECT 
 1 AS `account_id`,
 1 AS `total_learning_sessions`,
 1 AS `avg_patterns_found`,
 1 AS `avg_failures_analyzed`,
 1 AS `last_learning_session`*/;
SET character_set_client = @saved_cs_client;
DROP TABLE IF EXISTS `learning_summary`;
/*!50001 DROP VIEW IF EXISTS `learning_summary`*/;
SET @saved_cs_client     = @@character_set_client;
/*!50503 SET character_set_client = utf8mb4 */;
/*!50001 CREATE VIEW `learning_summary` AS SELECT 
 1 AS `account_id`,
 1 AS `total_experiences`,
 1 AS `avg_reward`,
 1 AS `last_experience`,
 1 AS `unique_states`,
 1 AS `unique_actions`,
 1 AS `q_table_entries`,
 1 AS `avg_q_value`*/;
SET character_set_client = @saved_cs_client;
DROP TABLE IF EXISTS `listing_drafts`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `listing_drafts` (
  `id` int NOT NULL AUTO_INCREMENT,
  `title` varchar(255) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `description` text COLLATE utf8mb4_general_ci,
  `bullet_points` json DEFAULT NULL,
  `seo_keywords` json DEFAULT NULL,
  `suggested_price` decimal(10,2) DEFAULT NULL,
  `category_id` varchar(50) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `status` varchar(20) COLLATE utf8mb4_general_ci DEFAULT 'draft',
  `created_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_status` (`status`)
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `llm_usage_logs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `llm_usage_logs` (
  `id` int NOT NULL AUTO_INCREMENT,
  `provider` varchar(50) NOT NULL,
  `model` varchar(100) NOT NULL,
  `input_tokens` int DEFAULT '0',
  `output_tokens` int DEFAULT '0',
  `total_tokens` int DEFAULT '0',
  `duration_ms` int DEFAULT '0',
  `context_type` varchar(50) DEFAULT 'generation',
  `user_id` varchar(50) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_user_created` (`user_id`,`created_at`)
) ENGINE=InnoDB AUTO_INCREMENT=2661 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `market_analyses`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `market_analyses` (
  `id` int NOT NULL AUTO_INCREMENT,
  `account_id` int NOT NULL,
  `category_id` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
  `brand` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `analysis_data` json NOT NULL,
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_account_id` (`account_id`),
  KEY `idx_category` (`category_id`),
  KEY `idx_created_at` (`created_at`)
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `market_intelligence`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `market_intelligence` (
  `id` int NOT NULL AUTO_INCREMENT,
  `account_id` varchar(100) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `condition_type` varchar(100) COLLATE utf8mb4_general_ci NOT NULL,
  `condition_data` json NOT NULL,
  `avg_performance` decimal(5,4) DEFAULT '0.0000',
  `sample_size` int DEFAULT '0',
  `confidence` decimal(5,4) DEFAULT '0.0000',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `trend_direction` enum('increasing','decreasing','stable') COLLATE utf8mb4_general_ci DEFAULT 'stable',
  `last_updated` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_account_condition` (`account_id`,`condition_type`),
  KEY `idx_confidence` (`confidence`),
  KEY `idx_trend_direction` (`trend_direction`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `market_keywords`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `market_keywords` (
  `id` int NOT NULL AUTO_INCREMENT,
  `keyword` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `category_id` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `search_volume` int DEFAULT '0',
  `competition_level` int DEFAULT '0' COMMENT '0-100',
  `avg_price` decimal(12,2) DEFAULT NULL,
  `trend` varchar(20) COLLATE utf8mb4_unicode_ci DEFAULT 'stable' COMMENT 'rising, falling, stable',
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_keyword` (`keyword`,`category_id`),
  KEY `idx_keyword` (`keyword`),
  KEY `idx_category` (`category_id`),
  KEY `idx_volume` (`search_volume`),
  KEY `idx_competition` (`competition_level`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `message_templates`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `message_templates` (
  `id` int NOT NULL AUTO_INCREMENT,
  `account_id` int NOT NULL,
  `name` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'Internal name for template',
  `event_trigger` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'paid, shipped, delivered, manual',
  `content` text COLLATE utf8mb4_unicode_ci NOT NULL,
  `is_active` tinyint(1) DEFAULT '1',
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_account_trigger` (`account_id`,`event_trigger`),
  CONSTRAINT `message_templates_ibfk_1` FOREIGN KEY (`account_id`) REFERENCES `ml_accounts` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `messages`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `messages` (
  `id` int NOT NULL AUTO_INCREMENT,
  `account_id` int NOT NULL,
  `message_id` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `thread_id` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `direction` varchar(20) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'sent, received',
  `content` text COLLATE utf8mb4_unicode_ci,
  `status` varchar(20) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `response_time_seconds` int DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_account` (`account_id`),
  KEY `idx_thread` (`thread_id`),
  KEY `idx_direction` (`direction`),
  KEY `idx_created` (`created_at`),
  CONSTRAINT `messages_ibfk_1` FOREIGN KEY (`account_id`) REFERENCES `ml_accounts` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `meta_learning_knowledge`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `meta_learning_knowledge` (
  `id` int NOT NULL AUTO_INCREMENT,
  `account_id` varchar(100) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `scenario_hash` varchar(64) COLLATE utf8mb4_general_ci NOT NULL,
  `meta_features` json NOT NULL,
  `extracted_patterns` json DEFAULT NULL,
  `adaptation_strategies` json DEFAULT NULL,
  `transferability_score` decimal(5,4) DEFAULT NULL,
  `success_probability` decimal(5,4) DEFAULT NULL,
  `application_count` int DEFAULT '0',
  `success_count` int DEFAULT '0',
  `last_applied` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_scenario_hash` (`scenario_hash`),
  KEY `idx_transferability` (`transferability_score` DESC),
  KEY `idx_success_probability` (`success_probability` DESC)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `migrations`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `migrations` (
  `id` int NOT NULL AUTO_INCREMENT,
  `migration` varchar(255) NOT NULL,
  `batch` int NOT NULL DEFAULT '1',
  `duration_ms` int unsigned DEFAULT NULL COMMENT 'Tempo de execução em ms',
  `error_message` text COMMENT 'Mensagem de erro se houve warning/tolerável',
  `applied_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `migration` (`migration`)
) ENGINE=InnoDB AUTO_INCREMENT=154 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `ml_accounts`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `ml_accounts` (
  `id` int NOT NULL AUTO_INCREMENT,
  `user_id` int NOT NULL,
  `ml_user_id` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
  `nickname` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `email` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `site_id` varchar(10) COLLATE utf8mb4_unicode_ci DEFAULT 'MLB',
  `access_token` text COLLATE utf8mb4_unicode_ci,
  `refresh_token` text COLLATE utf8mb4_unicode_ci,
  `token_expires_at` datetime NOT NULL,
  `status` enum('active','inactive','expired','disconnected') COLLATE utf8mb4_unicode_ci DEFAULT 'active',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `tokens_encrypted` tinyint(1) DEFAULT '0' COMMENT 'Indica se os tokens estão criptografados (1=sim, 0=não)',
  `last_synced_at` datetime DEFAULT NULL,
  `last_refresh_at` datetime DEFAULT NULL COMMENT 'Última renovação bem-sucedida de token',
  `refresh_failure_count` int unsigned NOT NULL DEFAULT '0' COMMENT 'Contador de falhas consecutivas de renovação',
  `last_refresh_error` text COLLATE utf8mb4_unicode_ci COMMENT 'Última mensagem de erro em tentativa de refresh',
  `last_oauth_connection_at` datetime DEFAULT NULL COMMENT 'Última autorização OAuth realizada pelo usuário',
  `last_token_refresh` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_ml_user` (`ml_user_id`),
  KEY `idx_user_id` (`user_id`),
  KEY `idx_status` (`status`),
  KEY `idx_token_expires` (`token_expires_at`),
  KEY `idx_tokens_encrypted` (`tokens_encrypted`),
  KEY `idx_last_refresh_at` (`last_refresh_at`),
  KEY `idx_health_dashboard` (`status`,`token_expires_at`,`refresh_failure_count`),
  KEY `idx_user_status` (`user_id`,`status`),
  KEY `idx_user_id_accounts` (`user_id`),
  KEY `idx_status_accounts` (`status`),
  CONSTRAINT `ml_accounts_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=1336 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `ml_anuncios_awa`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `ml_anuncios_awa` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `seller_id` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'ID interno do seller (ml_accounts.id)',
  `ml_user_id` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'ml_user_id do seller',
  `item_id` varchar(30) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'MLB1234567890',
  `title` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'Título do anúncio',
  `category_id` varchar(20) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `price` decimal(12,2) DEFAULT NULL,
  `original_price` decimal(12,2) DEFAULT NULL,
  `currency_id` varchar(10) COLLATE utf8mb4_unicode_ci DEFAULT 'BRL',
  `available_qty` int DEFAULT '0',
  `sold_qty` int DEFAULT '0',
  `status` varchar(20) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'active|paused|closed|under_review',
  `listing_type_id` varchar(30) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'gold_special|gold_pro|etc',
  `permalink` text COLLATE utf8mb4_unicode_ci,
  `thumbnail` text COLLATE utf8mb4_unicode_ci,
  `last_synced_at` datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `raw_data` json DEFAULT NULL COMMENT 'Payload completo da API ML',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_item_seller` (`item_id`,`seller_id`),
  KEY `idx_seller_id` (`seller_id`),
  KEY `idx_ml_user_id` (`ml_user_id`),
  KEY `idx_status` (`status`),
  KEY `idx_last_synced` (`last_synced_at`)
) ENGINE=InnoDB AUTO_INCREMENT=79211 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Cache de anúncios ML sincronizados pelo n8n';
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `ml_api_logs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `ml_api_logs` (
  `id` int NOT NULL AUTO_INCREMENT,
  `ml_account_id` int DEFAULT NULL,
  `method` varchar(10) COLLATE utf8mb4_unicode_ci NOT NULL,
  `endpoint` varchar(500) COLLATE utf8mb4_unicode_ci NOT NULL,
  `request_params` json DEFAULT NULL,
  `request_body` json DEFAULT NULL,
  `response_status` int DEFAULT NULL,
  `response_body` json DEFAULT NULL,
  `response_time_ms` int DEFAULT NULL,
  `error_message` text COLLATE utf8mb4_unicode_ci,
  `ip_address` varchar(45) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `user_agent` text COLLATE utf8mb4_unicode_ci,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_account_id` (`ml_account_id`),
  KEY `idx_method` (`method`),
  KEY `idx_status` (`response_status`),
  KEY `idx_created_at` (`created_at`),
  CONSTRAINT `ml_api_logs_ibfk_1` FOREIGN KEY (`ml_account_id`) REFERENCES `ml_accounts` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=255 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `ml_category_fees`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `ml_category_fees` (
  `id` int NOT NULL AUTO_INCREMENT,
  `category_id` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
  `category_name` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `taxa_classico` decimal(5,2) NOT NULL COMMENT '% comissão clássico',
  `taxa_premium` decimal(5,2) NOT NULL COMMENT '% comissão premium',
  `taxa_full` decimal(5,2) DEFAULT NULL COMMENT '% comissão Full (se diferente)',
  `frete_gratis_min` decimal(12,2) DEFAULT NULL COMMENT 'Valor mínimo para frete grátis',
  `atualizado_em` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_category` (`category_id`)
) ENGINE=InnoDB AUTO_INCREMENT=12 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `ml_claims`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `ml_claims` (
  `id` bigint unsigned NOT NULL,
  `order_id` bigint unsigned NOT NULL,
  `account_id` int NOT NULL,
  `platform` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'mercadolivre',
  `type` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `status` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `stage` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `reason` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `amount` decimal(12,2) DEFAULT NULL,
  `currency_id` varchar(3) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `date_created` datetime DEFAULT NULL,
  `last_updated` datetime DEFAULT NULL,
  `raw_data` json DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_order_id` (`order_id`),
  KEY `idx_account_status` (`account_id`,`status`),
  KEY `idx_type` (`type`),
  KEY `idx_date_created` (`date_created`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `ml_customers`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `ml_customers` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `account_id` int NOT NULL,
  `ml_buyer_id` bigint NOT NULL COMMENT 'ML buyer ID',
  `name` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `nickname` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `email` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `phone` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `state` varchar(10) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'UF do comprador',
  `city` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `total_orders` int NOT NULL DEFAULT '0',
  `total_purchases` decimal(12,2) NOT NULL DEFAULT '0.00' COMMENT 'Total gasto',
  `first_purchase_at` datetime DEFAULT NULL,
  `last_purchase_at` datetime DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_account_buyer` (`account_id`,`ml_buyer_id`),
  KEY `idx_account_id` (`account_id`),
  KEY `idx_total_purchases` (`total_purchases`),
  KEY `idx_last_purchase` (`last_purchase_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `ml_feedback`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `ml_feedback` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `ml_account_id` int NOT NULL,
  `feedback_id` varchar(64) COLLATE utf8mb4_unicode_ci NOT NULL,
  `order_id` varchar(64) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `rating` tinyint unsigned DEFAULT NULL COMMENT '1-5 star rating from ML',
  `message` text COLLATE utf8mb4_unicode_ci,
  `status` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `fulfilled` tinyint(1) DEFAULT NULL COMMENT '1 = buyer received item',
  `data` json DEFAULT NULL COMMENT 'Raw ML API payload',
  `feedback_date` datetime DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_account_feedback` (`ml_account_id`,`feedback_id`),
  KEY `idx_order_id` (`order_id`),
  KEY `idx_rating` (`rating`),
  KEY `idx_feedback_date` (`feedback_date`),
  KEY `idx_ml_account_id` (`ml_account_id`),
  CONSTRAINT `fk_ml_feedback_account` FOREIGN KEY (`ml_account_id`) REFERENCES `ml_accounts` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `ml_items`;
/*!50001 DROP VIEW IF EXISTS `ml_items`*/;
SET @saved_cs_client     = @@character_set_client;
/*!50503 SET character_set_client = utf8mb4 */;
/*!50001 CREATE VIEW `ml_items` AS SELECT 
 1 AS `id`,
 1 AS `ml_item_id`,
 1 AS `account_id`,
 1 AS `title`,
 1 AS `category_id`,
 1 AS `price`,
 1 AS `currency_id`,
 1 AS `available_quantity`,
 1 AS `status`,
 1 AS `condition_type`,
 1 AS `catalog_product_id`,
 1 AS `data`,
 1 AS `created_at`,
 1 AS `updated_at`*/;
SET character_set_client = @saved_cs_client;
DROP TABLE IF EXISTS `ml_model_performance`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `ml_model_performance` (
  `id` int NOT NULL AUTO_INCREMENT,
  `model_type` varchar(20) COLLATE utf8mb4_general_ci NOT NULL,
  `model_version` varchar(10) COLLATE utf8mb4_general_ci NOT NULL,
  `accuracy` decimal(5,3) NOT NULL,
  `precision_score` decimal(5,3) DEFAULT NULL,
  `recall_score` decimal(5,3) DEFAULT NULL,
  `f1_score` decimal(5,3) DEFAULT NULL,
  `training_samples` int DEFAULT NULL,
  `last_trained` datetime NOT NULL,
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_model_type` (`model_type`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `ml_orders`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `ml_orders` (
  `id` int NOT NULL AUTO_INCREMENT,
  `ml_order_id` bigint NOT NULL,
  `ml_account_id` int NOT NULL,
  `account_id` int DEFAULT NULL,
  `user_id` int DEFAULT NULL,
  `order_data` json NOT NULL,
  `status` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
  `pack_id` bigint DEFAULT NULL COMMENT 'ML pack/cart ID',
  `buyer_id` bigint DEFAULT NULL COMMENT 'ML buyer ID',
  `external_reference` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'External reference string',
  `total_amount` decimal(10,2) DEFAULT '0.00',
  `date_created` datetime NOT NULL,
  `synced_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `subtotal` decimal(10,2) DEFAULT '0.00' COMMENT 'Subtotal dos itens',
  `ml_commission` decimal(10,2) DEFAULT '0.00' COMMENT 'Comissão do ML',
  `payment_fee` decimal(10,2) DEFAULT '0.00' COMMENT 'Taxa Mercado Pago',
  `fixed_fee` decimal(10,2) DEFAULT '0.00' COMMENT 'Taxa fixa ML (<R$79)',
  `shipping_cost` decimal(10,2) DEFAULT '0.00' COMMENT 'Custo do frete',
  `marketplace_fee` decimal(10,2) DEFAULT '0.00' COMMENT 'Marketplace fee total',
  `discount_amount` decimal(10,2) DEFAULT '0.00' COMMENT 'Cupons/descontos',
  `taxes` decimal(10,2) DEFAULT '0.00' COMMENT 'Impostos',
  `product_cost` decimal(10,2) DEFAULT '0.00' COMMENT 'Custo do produto',
  `total_costs` decimal(10,2) DEFAULT '0.00' COMMENT 'Total de custos',
  `net_revenue` decimal(10,2) DEFAULT '0.00' COMMENT 'Receita líquida',
  `gross_margin` decimal(5,2) DEFAULT '0.00' COMMENT 'Margem bruta %',
  `net_profit` decimal(10,2) DEFAULT '0.00' COMMENT 'Lucro líquido',
  `roi` decimal(5,2) DEFAULT '0.00' COMMENT 'ROI %',
  `is_profitable` tinyint(1) DEFAULT '1' COMMENT 'É lucrativo?',
  `is_full` tinyint(1) DEFAULT '0' COMMENT 'É Full?',
  `is_flex` tinyint(1) DEFAULT '0' COMMENT 'É Flex?',
  `free_shipping` tinyint(1) DEFAULT '0' COMMENT 'Frete grátis?',
  `listing_type` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'Tipo anúncio (gold, premium)',
  `payment_method` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'Forma de pagamento',
  `installments` int DEFAULT '1' COMMENT 'Parcelas',
  `items_count` int DEFAULT '1' COMMENT 'Qtd itens',
  `shipped_at` datetime DEFAULT NULL COMMENT 'Data de envio',
  `delivered_at` datetime DEFAULT NULL COMMENT 'Data de entrega',
  `handling_time` int DEFAULT NULL COMMENT 'Tempo manuseio (min)',
  `delivery_time` int DEFAULT NULL COMMENT 'Tempo entrega (min)',
  `is_delayed` tinyint(1) DEFAULT '0' COMMENT 'Atrasado?',
  `payment_reconciled` tinyint(1) DEFAULT '0' COMMENT 'Settlement reconciled flag',
  `category_id` varchar(30) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'Primary category from order items',
  `created_at` datetime DEFAULT NULL COMMENT 'Alias for date_created for service compatibility',
  PRIMARY KEY (`id`),
  UNIQUE KEY `ml_order_id` (`ml_order_id`),
  KEY `idx_account_id` (`ml_account_id`),
  KEY `idx_status` (`status`),
  KEY `idx_date_created` (`date_created`),
  KEY `idx_synced_at` (`synced_at`),
  KEY `idx_user_id` (`user_id`),
  KEY `idx_status_date` (`status`,`date_created`),
  KEY `idx_account_status` (`ml_account_id`,`status`),
  KEY `idx_is_profitable` (`is_profitable`),
  KEY `idx_net_profit` (`net_profit`),
  KEY `idx_listing_type` (`listing_type`),
  KEY `idx_is_full` (`is_full`),
  KEY `idx_shipped_at` (`shipped_at`),
  KEY `idx_delivered_at` (`delivered_at`),
  KEY `idx_pack_id` (`pack_id`),
  KEY `idx_buyer_id` (`buyer_id`),
  KEY `idx_category_id` (`category_id`),
  KEY `idx_shipping_cost` (`shipping_cost`),
  CONSTRAINT `fk_ml_orders_user_id` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `ml_orders_ibfk_1` FOREIGN KEY (`ml_account_id`) REFERENCES `ml_accounts` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=384405 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `ml_payments`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `ml_payments` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `ml_account_id` int NOT NULL,
  `payment_id` varchar(64) COLLATE utf8mb4_unicode_ci NOT NULL,
  `order_id` varchar(64) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `status` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `amount` decimal(12,2) DEFAULT NULL,
  `currency_id` varchar(10) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `payment_method` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `data` json DEFAULT NULL COMMENT 'Raw ML API payload',
  `paid_at` datetime DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_account_payment` (`ml_account_id`,`payment_id`),
  KEY `idx_order_id` (`order_id`),
  KEY `idx_status` (`status`),
  KEY `idx_paid_at` (`paid_at`),
  KEY `idx_ml_account_id` (`ml_account_id`),
  CONSTRAINT `fk_ml_payments_account` FOREIGN KEY (`ml_account_id`) REFERENCES `ml_accounts` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `ml_pedidos_awa`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `ml_pedidos_awa` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `seller_id` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
  `ml_user_id` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
  `order_id` varchar(30) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'ID do pedido ML',
  `status` varchar(30) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'paid|shipped|delivered|cancelled',
  `total_amount` decimal(12,2) DEFAULT NULL,
  `currency_id` varchar(10) COLLATE utf8mb4_unicode_ci DEFAULT 'BRL',
  `date_created` datetime DEFAULT NULL,
  `date_closed` datetime DEFAULT NULL,
  `buyer_id` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `buyer_nickname` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `item_id` varchar(30) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `item_title` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `quantity` int DEFAULT NULL,
  `unit_price` decimal(12,2) DEFAULT NULL,
  `last_synced_at` datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  `raw_data` json DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_order_seller` (`order_id`,`seller_id`),
  KEY `idx_seller_id` (`seller_id`),
  KEY `idx_status` (`status`),
  KEY `idx_date_closed` (`date_closed`)
) ENGINE=InnoDB AUTO_INCREMENT=79234 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Cache de pedidos ML sincronizados pelo n8n';
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `ml_predictions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `ml_predictions` (
  `id` int NOT NULL AUTO_INCREMENT,
  `account_id` int NOT NULL,
  `prediction_type` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'sales, demand, pricing, trending',
  `target_id` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'item_id ou category_id',
  `prediction_date` date NOT NULL,
  `predicted_value` decimal(12,2) DEFAULT NULL,
  `confidence` decimal(5,2) DEFAULT NULL,
  `actual_value` decimal(12,2) DEFAULT NULL,
  `accuracy` decimal(5,2) DEFAULT NULL,
  `model_used` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_account` (`account_id`),
  KEY `idx_type` (`prediction_type`),
  KEY `idx_target` (`target_id`),
  KEY `idx_date` (`prediction_date`),
  KEY `idx_created` (`created_at`),
  CONSTRAINT `ml_predictions_ibfk_1` FOREIGN KEY (`account_id`) REFERENCES `ml_accounts` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `ml_proxies`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `ml_proxies` (
  `id` int NOT NULL AUTO_INCREMENT,
  `type` enum('http','https','socks4','socks5') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'http',
  `host` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `port` varchar(10) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '8080',
  `username` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `password` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `country` char(2) COLLATE utf8mb4_unicode_ci DEFAULT 'BR',
  `priority` int DEFAULT '50',
  `status` enum('active','inactive','testing') COLLATE utf8mb4_unicode_ci DEFAULT 'active',
  `success_count` int DEFAULT '0',
  `failure_count` int DEFAULT '0',
  `last_used_at` datetime DEFAULT NULL,
  `last_success_at` datetime DEFAULT NULL,
  `last_failure_at` datetime DEFAULT NULL,
  `last_error` text COLLATE utf8mb4_unicode_ci,
  `avg_response_time` int DEFAULT NULL COMMENT 'em milissegundos',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_proxy` (`host`,`port`),
  KEY `idx_status` (`status`),
  KEY `idx_priority` (`priority` DESC),
  KEY `idx_country` (`country`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `ml_proxy_logs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `ml_proxy_logs` (
  `id` bigint NOT NULL AUTO_INCREMENT,
  `proxy_id` int NOT NULL,
  `endpoint` varchar(500) COLLATE utf8mb4_unicode_ci NOT NULL,
  `status_code` int DEFAULT NULL,
  `response_time` int DEFAULT NULL COMMENT 'em milissegundos',
  `success` tinyint(1) DEFAULT '0',
  `error_message` text COLLATE utf8mb4_unicode_ci,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_proxy_id` (`proxy_id`),
  KEY `idx_created_at` (`created_at`),
  CONSTRAINT `ml_proxy_logs_ibfk_1` FOREIGN KEY (`proxy_id`) REFERENCES `ml_proxies` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `ml_questions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `ml_questions` (
  `id` int NOT NULL AUTO_INCREMENT,
  `account_id` int NOT NULL,
  `question_id` bigint NOT NULL,
  `item_id` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
  `seller_id` bigint NOT NULL,
  `question_text` text COLLATE utf8mb4_unicode_ci,
  `answer_text` text COLLATE utf8mb4_unicode_ci,
  `status` enum('UNANSWERED','ANSWERED','CLOSED_UNANSWERED','UNDER_REVIEW') COLLATE utf8mb4_unicode_ci DEFAULT 'UNANSWERED',
  `date_created` datetime DEFAULT NULL,
  `answer_date` datetime DEFAULT NULL,
  `from_user_id` bigint DEFAULT NULL,
  `from_user_nickname` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `sentiment` varchar(20) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `intent` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `urgency` int DEFAULT '0',
  `analysis_raw` json DEFAULT NULL,
  `ai_draft` text COLLATE utf8mb4_unicode_ci,
  `confidence_score` int DEFAULT '0',
  PRIMARY KEY (`id`),
  UNIQUE KEY `question_id` (`question_id`),
  KEY `idx_account` (`account_id`),
  KEY `idx_item` (`item_id`),
  KEY `idx_status` (`status`),
  KEY `idx_date` (`date_created`),
  KEY `idx_status_questions` (`status`),
  KEY `idx_date_created_questions` (`date_created`),
  KEY `idx_account_id_questions` (`account_id`)
) ENGINE=InnoDB AUTO_INCREMENT=155813 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `ml_research_cache`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `ml_research_cache` (
  `id` int NOT NULL AUTO_INCREMENT,
  `cache_key` varchar(255) NOT NULL,
  `cache_data` longtext,
  `expires_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `cache_key` (`cache_key`),
  KEY `idx_key` (`cache_key`),
  KEY `idx_expires` (`expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `ml_sync_status`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `ml_sync_status` (
  `id` int NOT NULL AUTO_INCREMENT,
  `ml_account_id` int NOT NULL,
  `sync_type` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'items, orders, messages, user_info',
  `last_sync_at` datetime DEFAULT NULL,
  `next_sync_at` datetime DEFAULT NULL,
  `status` enum('idle','running','error','completed') COLLATE utf8mb4_unicode_ci DEFAULT 'idle',
  `total_records` int DEFAULT '0',
  `synced_records` int DEFAULT '0',
  `error_count` int DEFAULT '0',
  `last_error` text COLLATE utf8mb4_unicode_ci,
  `started_at` datetime DEFAULT NULL,
  `completed_at` datetime DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_sync` (`ml_account_id`,`sync_type`),
  KEY `idx_account_id` (`ml_account_id`),
  KEY `idx_sync_type` (`sync_type`),
  KEY `idx_status` (`status`),
  KEY `idx_next_sync` (`next_sync_at`),
  CONSTRAINT `ml_sync_status_ibfk_1` FOREIGN KEY (`ml_account_id`) REFERENCES `ml_accounts` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=5 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `mobile_devices`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `mobile_devices` (
  `id` int NOT NULL AUTO_INCREMENT,
  `user_id` int NOT NULL,
  `device_id` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `fcm_token` text COLLATE utf8mb4_unicode_ci,
  `platform` enum('android','ios','web','unknown') COLLATE utf8mb4_unicode_ci DEFAULT 'unknown',
  `app_version` varchar(20) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `last_active` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_device` (`user_id`,`device_id`),
  KEY `idx_user` (`user_id`),
  KEY `idx_platform` (`platform`),
  CONSTRAINT `mobile_devices_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `model_performance`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `model_performance` (
  `id` int NOT NULL AUTO_INCREMENT,
  `account_id` varchar(100) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `model_name` varchar(100) COLLATE utf8mb4_general_ci NOT NULL,
  `model_version` varchar(50) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `accuracy_improvement` decimal(5,4) DEFAULT '0.0000',
  `training_samples` int DEFAULT '0',
  `validation_accuracy` decimal(5,4) DEFAULT '0.0000',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `last_updated` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_account_model` (`account_id`,`model_name`),
  KEY `idx_last_updated` (`last_updated`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `monitored_competitors`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `monitored_competitors` (
  `id` int NOT NULL AUTO_INCREMENT,
  `account_id` int DEFAULT NULL,
  `seller_id` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `nickname` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT '',
  `reputation_level` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT 'unknown',
  `total_items` int DEFAULT '0',
  `sales_completed` int DEFAULT '0',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_account_seller` (`account_id`,`seller_id`),
  KEY `idx_account` (`account_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `monitored_keywords`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `monitored_keywords` (
  `id` int NOT NULL AUTO_INCREMENT,
  `account_id` int NOT NULL,
  `keyword` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `category_id` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `priority` int DEFAULT '0',
  `active` tinyint(1) DEFAULT '1',
  `last_trend_score` decimal(3,2) DEFAULT NULL,
  `last_check_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_account_keyword` (`account_id`,`keyword`),
  KEY `idx_account_id` (`account_id`),
  KEY `idx_active` (`active`),
  KEY `idx_priority` (`priority`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `multi_agent_coordination`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `multi_agent_coordination` (
  `id` int NOT NULL AUTO_INCREMENT,
  `account_id` varchar(100) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `coordination_id` varchar(100) COLLATE utf8mb4_general_ci NOT NULL,
  `agent_type` varchar(50) COLLATE utf8mb4_general_ci NOT NULL,
  `agent_id` varchar(100) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `agent_action` varchar(200) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `agent_reward` decimal(10,6) DEFAULT NULL,
  `coordination_score` decimal(5,4) DEFAULT NULL,
  `conflict_detected` tinyint(1) DEFAULT '0',
  `conflict_resolution` json DEFAULT NULL,
  `final_action` varchar(200) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `coordination_efficiency` decimal(5,4) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_coordination_id` (`coordination_id`),
  KEY `idx_account_agent` (`account_id`,`agent_type`),
  KEY `idx_created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `neural_network_summary`;
/*!50001 DROP VIEW IF EXISTS `neural_network_summary`*/;
SET @saved_cs_client     = @@character_set_client;
/*!50503 SET character_set_client = utf8mb4 */;
/*!50001 CREATE VIEW `neural_network_summary` AS SELECT 
 1 AS `account_id`,
 1 AS `network_type`,
 1 AS `network_name`,
 1 AS `version`,
 1 AS `is_active`,
 1 AS `training_steps`,
 1 AS `avg_loss`,
 1 AS `best_loss`,
 1 AS `latest_step`,
 1 AS `target_network_updated`*/;
SET character_set_client = @saved_cs_client;
DROP TABLE IF EXISTS `neural_networks`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `neural_networks` (
  `id` int NOT NULL AUTO_INCREMENT,
  `account_id` varchar(100) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `network_type` enum('dqn','actor_critic','ppo','meta_learning') COLLATE utf8mb4_general_ci NOT NULL,
  `network_name` varchar(200) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `config_data` json NOT NULL,
  `weights_data` longblob,
  `architecture` json DEFAULT NULL,
  `training_parameters` json DEFAULT NULL,
  `performance_metrics` json DEFAULT NULL,
  `version` int DEFAULT '1',
  `is_active` tinyint(1) DEFAULT '1',
  `target_network_updated` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_account_type` (`account_id`,`network_type`),
  KEY `idx_is_active` (`is_active`),
  KEY `idx_updated_at` (`updated_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `notification_logs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `notification_logs` (
  `id` int NOT NULL AUTO_INCREMENT,
  `type` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL,
  `recipient` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `subject` text COLLATE utf8mb4_unicode_ci,
  `status` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL,
  `error_message` text COLLATE utf8mb4_unicode_ci,
  `metadata` json DEFAULT NULL,
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_type` (`type`),
  KEY `idx_status` (`status`),
  KEY `idx_created` (`created_at`),
  KEY `idx_recipient` (`recipient`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `notification_preferences`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `notification_preferences` (
  `id` int NOT NULL AUTO_INCREMENT,
  `user_id` int NOT NULL,
  `email_alerts` tinyint(1) DEFAULT '1',
  `whatsapp_alerts` tinyint(1) DEFAULT '0',
  `sms_alerts` tinyint(1) DEFAULT '0',
  `alert_priority_threshold` varchar(20) COLLATE utf8mb4_unicode_ci DEFAULT 'medium',
  `quiet_hours_start` time DEFAULT NULL,
  `quiet_hours_end` time DEFAULT NULL,
  `daily_report` tinyint(1) DEFAULT '0',
  `weekly_report` tinyint(1) DEFAULT '1',
  `monthly_report` tinyint(1) DEFAULT '1',
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_user` (`user_id`),
  CONSTRAINT `notification_preferences_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `notification_settings`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `notification_settings` (
  `id` int NOT NULL AUTO_INCREMENT,
  `account_id` int NOT NULL,
  `sound_enabled` tinyint(1) DEFAULT '1',
  `sound_volume` int DEFAULT '80',
  `sound_order` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT 'order_notification',
  `sound_question` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT 'question_notification',
  `sound_message` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT 'message_notification',
  `desktop_enabled` tinyint(1) DEFAULT '1',
  `polling_interval` int DEFAULT '30',
  `quiet_hours_start` time DEFAULT NULL,
  `quiet_hours_end` time DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `email_orders` tinyint(1) DEFAULT '1',
  `email_questions` tinyint(1) DEFAULT '1',
  `whatsapp_orders` tinyint(1) DEFAULT '0',
  `whatsapp_questions` tinyint(1) DEFAULT '0',
  `whatsapp_low_stock` tinyint(1) DEFAULT '0',
  PRIMARY KEY (`id`),
  UNIQUE KEY `account_id` (`account_id`)
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `notification_subscriptions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `notification_subscriptions` (
  `id` int NOT NULL AUTO_INCREMENT,
  `channel_id` int NOT NULL,
  `event_type` enum('price_change','competitor_alert','margin_alert','rule_executed','schedule_executed','bulk_completed','ab_test_complete','optimization_suggestion') COLLATE utf8mb4_unicode_ci NOT NULL,
  `min_severity` tinyint DEFAULT '1' COMMENT '1=info, 2=warning, 3=error, 4=critical',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_channel_event` (`channel_id`,`event_type`),
  KEY `idx_channel_id` (`channel_id`),
  CONSTRAINT `notification_subscriptions_ibfk_1` FOREIGN KEY (`channel_id`) REFERENCES `notification_channels` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `notifications`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `notifications` (
  `id` int NOT NULL AUTO_INCREMENT,
  `user_id` int NOT NULL,
  `type` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'general',
  `title` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `message` text COLLATE utf8mb4_unicode_ci NOT NULL,
  `data` json DEFAULT NULL COMMENT 'Dados adicionais da notificação',
  `is_read` tinyint(1) DEFAULT '0',
  `is_email_sent` tinyint(1) DEFAULT '0',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `read_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_user_id` (`user_id`),
  KEY `idx_type` (`type`),
  KEY `idx_is_read` (`is_read`),
  KEY `idx_created_at` (`created_at`)
) ENGINE=InnoDB AUTO_INCREMENT=3935 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `offline_queue`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `offline_queue` (
  `id` int NOT NULL AUTO_INCREMENT,
  `user_id` int NOT NULL,
  `action_type` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
  `endpoint` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `method` varchar(10) COLLATE utf8mb4_unicode_ci NOT NULL,
  `payload` json DEFAULT NULL,
  `status` enum('pending','processing','completed','failed') COLLATE utf8mb4_unicode_ci DEFAULT 'pending',
  `attempts` int DEFAULT '0',
  `max_attempts` int DEFAULT '3',
  `error_message` text COLLATE utf8mb4_unicode_ci,
  `processed_at` datetime DEFAULT NULL,
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_user_id` (`user_id`),
  KEY `idx_status` (`status`),
  KEY `idx_created_at` (`created_at`),
  CONSTRAINT `offline_queue_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `openclaw_webhooks`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `openclaw_webhooks` (
  `id` int NOT NULL AUTO_INCREMENT,
  `user_id` int NOT NULL,
  `name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `url` varchar(2048) COLLATE utf8mb4_unicode_ci NOT NULL,
  `secret` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'HMAC secret for signature verification',
  `events` json NOT NULL COMMENT 'Array of event types to subscribe',
  `is_active` tinyint(1) DEFAULT '1',
  `last_triggered_at` timestamp NULL DEFAULT NULL,
  `failure_count` int DEFAULT '0',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_user` (`user_id`),
  KEY `idx_active` (`is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `optimization_change_log`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `optimization_change_log` (
  `id` int NOT NULL AUTO_INCREMENT,
  `item_id` varchar(100) COLLATE utf8mb4_general_ci NOT NULL,
  `field_name` varchar(50) COLLATE utf8mb4_general_ci NOT NULL,
  `old_value` text COLLATE utf8mb4_general_ci,
  `new_value` text COLLATE utf8mb4_general_ci,
  `change_type` enum('manual','autopilot','scheduled') COLLATE utf8mb4_general_ci DEFAULT 'autopilot',
  `execution_id` varchar(100) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_item_id` (`item_id`),
  KEY `idx_field_name` (`field_name`),
  KEY `idx_change_type` (`change_type`),
  KEY `idx_created_at` (`created_at`),
  KEY `idx_execution_id` (`execution_id`),
  CONSTRAINT `optimization_change_log_ibfk_1` FOREIGN KEY (`execution_id`) REFERENCES `autopilot_execution_sessions` (`execution_id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `optimization_schedules`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `optimization_schedules` (
  `id` int NOT NULL AUTO_INCREMENT,
  `account_id` int NOT NULL,
  `task_type` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
  `schedule_type` enum('hourly','daily','weekly','monthly') COLLATE utf8mb4_unicode_ci NOT NULL,
  `config_json` json DEFAULT NULL,
  `enabled` tinyint(1) DEFAULT '1',
  `last_run_at` datetime DEFAULT NULL,
  `run_count` int DEFAULT '0',
  `created_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_account_id` (`account_id`),
  KEY `idx_enabled` (`enabled`),
  KEY `idx_schedule_type` (`schedule_type`),
  KEY `idx_last_run_at` (`last_run_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `optimization_strategies`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `optimization_strategies` (
  `id` int NOT NULL AUTO_INCREMENT,
  `account_id` varchar(100) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `strategy_name` varchar(200) COLLATE utf8mb4_general_ci NOT NULL,
  `strategy_type` enum('title','description','price','timing','comprehensive') COLLATE utf8mb4_general_ci NOT NULL,
  `strategy_data` json NOT NULL,
  `success_rate` decimal(5,4) DEFAULT '0.0000',
  `avg_roi` decimal(10,2) DEFAULT '0.00',
  `usage_count` int DEFAULT '0',
  `last_used` timestamp NULL DEFAULT NULL,
  `active` tinyint(1) DEFAULT '1',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_account_type` (`account_id`,`strategy_type`),
  KEY `idx_success_rate` (`success_rate`),
  KEY `idx_active` (`active`),
  KEY `idx_optimization_strategies_composite` (`account_id`,`strategy_type`,`success_rate`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `order_items`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `order_items` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `order_id` bigint NOT NULL COMMENT 'References ml_orders.ml_order_id',
  `item_id` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'ML item ID (e.g., MLB123456)',
  `title` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `quantity` int NOT NULL DEFAULT '1',
  `unit_price` decimal(10,2) NOT NULL DEFAULT '0.00',
  `total_price` decimal(10,2) GENERATED ALWAYS AS ((`quantity` * `unit_price`)) STORED,
  `category_id` varchar(30) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `variation_id` bigint DEFAULT NULL,
  `sku` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `condition_type` varchar(20) COLLATE utf8mb4_unicode_ci DEFAULT 'new' COMMENT 'new, used, refurbished',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_order_id` (`order_id`),
  KEY `idx_item_id` (`item_id`),
  KEY `idx_sku` (`sku`),
  KEY `idx_category_id` (`category_id`),
  KEY `idx_created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `password_resets`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `password_resets` (
  `id` int NOT NULL AUTO_INCREMENT,
  `email` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `token` varchar(64) COLLATE utf8mb4_unicode_ci NOT NULL,
  `expires_at` timestamp NOT NULL,
  `used_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `token` (`token`),
  KEY `idx_email` (`email`),
  KEY `idx_token` (`token`),
  KEY `idx_expires` (`expires_at`)
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `performance_logs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `performance_logs` (
  `id` int NOT NULL AUTO_INCREMENT,
  `endpoint` varchar(200) COLLATE utf8mb4_general_ci NOT NULL,
  `method` varchar(10) COLLATE utf8mb4_general_ci NOT NULL,
  `response_time` decimal(8,3) NOT NULL,
  `memory_usage` int NOT NULL,
  `status_code` int NOT NULL,
  `user_id` int DEFAULT NULL,
  `ip_address` varchar(45) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `user_agent` text COLLATE utf8mb4_general_ci,
  `timestamp` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_endpoint_time` (`endpoint`,`timestamp`),
  KEY `idx_response_time` (`response_time`),
  KEY `idx_timestamp` (`timestamp`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `performance_metrics`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `performance_metrics` (
  `id` int NOT NULL AUTO_INCREMENT,
  `duration_ms` decimal(10,2) DEFAULT NULL,
  `memory_mb` decimal(10,2) DEFAULT NULL,
  `query_count` int DEFAULT NULL,
  `url` varchar(500) DEFAULT NULL,
  `method` varchar(10) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_created` (`created_at`),
  KEY `idx_url` (`url`(255))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `performance_monitoring_schedule`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `performance_monitoring_schedule` (
  `id` int NOT NULL AUTO_INCREMENT,
  `item_id` varchar(100) COLLATE utf8mb4_general_ci NOT NULL,
  `execution_id` varchar(100) COLLATE utf8mb4_general_ci NOT NULL,
  `check_time` timestamp NOT NULL,
  `baseline_metrics` json DEFAULT NULL,
  `actual_metrics` json DEFAULT NULL,
  `performance_delta` json DEFAULT NULL,
  `status` enum('pending','completed','failed') COLLATE utf8mb4_general_ci DEFAULT 'pending',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `completed_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_item_check_time` (`item_id`,`check_time`),
  KEY `idx_status` (`status`),
  KEY `idx_check_time` (`check_time`),
  KEY `idx_execution_id` (`execution_id`),
  CONSTRAINT `performance_monitoring_schedule_ibfk_1` FOREIGN KEY (`execution_id`) REFERENCES `autopilot_execution_sessions` (`execution_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `prediction_logs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `prediction_logs` (
  `id` int NOT NULL AUTO_INCREMENT,
  `target_id` varchar(50) COLLATE utf8mb4_general_ci NOT NULL,
  `prediction_type` varchar(30) COLLATE utf8mb4_general_ci NOT NULL,
  `prediction_data` json NOT NULL,
  `accuracy_score` decimal(5,3) DEFAULT NULL,
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_target_id` (`target_id`),
  KEY `idx_type` (`prediction_type`)
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `predictive_intelligence_cache`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `predictive_intelligence_cache` (
  `id` int NOT NULL AUTO_INCREMENT,
  `account_id` varchar(100) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `prediction_type` enum('market_trends','competitor_behavior','pricing_optimization','seasonal_patterns','keyword_trends','algorithm_changes') COLLATE utf8mb4_general_ci NOT NULL,
  `prediction_scope` varchar(200) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `insights_data` json NOT NULL,
  `confidence_level` decimal(5,4) NOT NULL,
  `time_horizon_days` int DEFAULT NULL,
  `model_version` varchar(50) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `actual_outcome` json DEFAULT NULL,
  `prediction_accuracy` decimal(5,4) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `expires_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_account_type` (`account_id`,`prediction_type`),
  KEY `idx_confidence` (`confidence_level` DESC),
  KEY `idx_expires_at` (`expires_at`),
  KEY `idx_created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET collation_connection  = utf8mb4_0900_ai_ci */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION' */ ;
DELIMITER ;;
/*!50003 CREATE*/  /*!50003 TRIGGER `cleanup_expired_predictions` AFTER INSERT ON `predictive_intelligence_cache` FOR EACH ROW BEGIN

    DELETE FROM predictive_intelligence_cache 
    WHERE expires_at < NOW() 
    LIMIT 500;
END */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
DROP TABLE IF EXISTS `price_adjustments`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `price_adjustments` (
  `id` int NOT NULL AUTO_INCREMENT,
  `account_id` int NOT NULL,
  `item_id` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
  `old_price` decimal(12,2) NOT NULL,
  `new_price` decimal(12,2) NOT NULL,
  `strategy` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'competition, demand, inventory, manual',
  `confidence` decimal(5,2) DEFAULT NULL,
  `reason` text COLLATE utf8mb4_unicode_ci,
  `applied_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_account` (`account_id`),
  KEY `idx_item` (`item_id`),
  KEY `idx_strategy` (`strategy`),
  KEY `idx_applied` (`applied_at`),
  CONSTRAINT `price_adjustments_ibfk_1` FOREIGN KEY (`account_id`) REFERENCES `ml_accounts` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `price_elasticity_analysis`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `price_elasticity_analysis` (
  `id` int NOT NULL AUTO_INCREMENT,
  `account_id` int NOT NULL,
  `item_id` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
  `elasticity_coefficient` decimal(5,2) NOT NULL COMMENT 'Coeficiente de elasticidade',
  `interpretation` enum('highly_elastic','elastic','moderately_elastic','inelastic') COLLATE utf8mb4_unicode_ci NOT NULL,
  `data_points_count` int NOT NULL COMMENT 'Quantidade de dados históricos usados',
  `scenarios` json NOT NULL COMMENT 'Cenários simulados (+10%, -10%, etc)',
  `recommendations` text COLLATE utf8mb4_unicode_ci,
  `analyzed_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_item_analysis` (`item_id`,`analyzed_at`),
  KEY `idx_account_id` (`account_id`),
  KEY `idx_item_id` (`item_id`),
  KEY `idx_analyzed_at` (`analyzed_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Análises de elasticidade de preço por produto';
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `price_history`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `price_history` (
  `id` int NOT NULL AUTO_INCREMENT,
  `category_id` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
  `brand` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `avg_price` decimal(10,2) NOT NULL,
  `min_price` decimal(10,2) NOT NULL,
  `max_price` decimal(10,2) NOT NULL,
  `total_items` int DEFAULT '0',
  `recorded_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_category_brand` (`category_id`,`brand`),
  KEY `idx_recorded_at` (`recorded_at`),
  KEY `idx_category_brand_date` (`category_id`,`brand`,`recorded_at`),
  KEY `idx_date_desc` (`recorded_at` DESC)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `pricing_campaigns`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `pricing_campaigns` (
  `id` int NOT NULL AUTO_INCREMENT,
  `account_id` int NOT NULL,
  `name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `description` text COLLATE utf8mb4_unicode_ci,
  `discount_type` enum('percent','fixed') COLLATE utf8mb4_unicode_ci NOT NULL,
  `discount_value` decimal(12,2) NOT NULL,
  `starts_at` datetime NOT NULL,
  `ends_at` datetime NOT NULL,
  `rollback_enabled` tinyint(1) DEFAULT '1',
  `status` enum('draft','scheduled','active','completed','cancelled') COLLATE utf8mb4_unicode_ci DEFAULT 'draft',
  `items` json NOT NULL COMMENT 'Array of item_ids with original prices',
  `total_items` int DEFAULT '0',
  `executed_items` int DEFAULT '0',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_account_status` (`account_id`,`status`),
  KEY `idx_date_range` (`starts_at`,`ends_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `pricing_elasticity_data`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `pricing_elasticity_data` (
  `id` bigint NOT NULL AUTO_INCREMENT,
  `account_id` int NOT NULL,
  `item_id` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
  `price_point` decimal(12,2) NOT NULL,
  `quantity_sold` int NOT NULL,
  `revenue` decimal(14,2) NOT NULL,
  `period_start` date NOT NULL,
  `period_end` date NOT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_account_item` (`account_id`,`item_id`),
  KEY `idx_period` (`period_start`,`period_end`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `pricing_history`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `pricing_history` (
  `id` bigint NOT NULL AUTO_INCREMENT,
  `account_id` int NOT NULL,
  `item_id` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
  `preco_anterior` decimal(12,2) NOT NULL,
  `preco_novo` decimal(12,2) NOT NULL,
  `percentual_mudanca` decimal(6,2) NOT NULL,
  `origem` enum('manual','auto','promocao','concorrencia','demanda','liquidacao') COLLATE utf8mb4_unicode_ci DEFAULT 'manual',
  `motivo` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'Razão da alteração',
  `estrategia_usada` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'Estratégia aplicada',
  `preco_concorrente_min` decimal(12,2) DEFAULT NULL COMMENT 'Menor preço concorrente',
  `preco_concorrente_medio` decimal(12,2) DEFAULT NULL COMMENT 'Preço médio concorrentes',
  `qtd_concorrentes` int DEFAULT '0',
  `margem_anterior` decimal(6,2) DEFAULT NULL COMMENT 'Margem antes da mudança %',
  `margem_nova` decimal(6,2) DEFAULT NULL COMMENT 'Margem após mudança %',
  `lucro_unitario_novo` decimal(12,2) DEFAULT NULL COMMENT 'Lucro por unidade R$',
  `vendas_antes_7d` int DEFAULT NULL COMMENT 'Vendas nos 7 dias anteriores',
  `vendas_depois_7d` int DEFAULT NULL COMMENT 'Vendas nos 7 dias posteriores',
  `visitas_antes_7d` int DEFAULT NULL,
  `visitas_depois_7d` int DEFAULT NULL,
  `alerta_ranking` enum('verde','amarelo','vermelho') COLLATE utf8mb4_unicode_ci DEFAULT 'verde',
  `data_mudanca` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_account_item` (`account_id`,`item_id`),
  KEY `idx_data` (`data_mudanca`),
  KEY `idx_origem` (`origem`),
  KEY `idx_account_date` (`account_id`,`data_mudanca`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `pricing_optimization_runs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `pricing_optimization_runs` (
  `id` int NOT NULL AUTO_INCREMENT,
  `categories_processed` int DEFAULT NULL,
  `items_processed` int DEFAULT NULL,
  `auto_applied` int DEFAULT NULL,
  `started_at` datetime DEFAULT NULL,
  `completed_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `pricing_ranking_alerts`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `pricing_ranking_alerts` (
  `id` int NOT NULL AUTO_INCREMENT,
  `account_id` int NOT NULL,
  `item_id` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
  `tipo_alerta` enum('aumento_preco','queda_vendas','perda_posicao','concorrente_agressivo') COLLATE utf8mb4_unicode_ci NOT NULL,
  `nivel` enum('verde','amarelo','vermelho') COLLATE utf8mb4_unicode_ci NOT NULL,
  `mensagem` text COLLATE utf8mb4_unicode_ci NOT NULL,
  `preco_atual` decimal(12,2) DEFAULT NULL,
  `preco_recomendado` decimal(12,2) DEFAULT NULL,
  `variacao_detectada` decimal(6,2) DEFAULT NULL,
  `lido` tinyint(1) DEFAULT '0',
  `acao_tomada` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `resolvido` tinyint(1) DEFAULT '0',
  `resolvido_em` timestamp NULL DEFAULT NULL,
  `criado_em` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_account` (`account_id`),
  KEY `idx_account_item` (`account_id`,`item_id`),
  KEY `idx_nivel` (`nivel`),
  KEY `idx_nao_lido` (`account_id`,`lido`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `pricing_rule_executions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `pricing_rule_executions` (
  `id` int NOT NULL AUTO_INCREMENT,
  `rule_id` int NOT NULL,
  `account_id` int NOT NULL,
  `item_id` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
  `rule_triggered` json NOT NULL COMMENT 'Regra específica que disparou',
  `old_price` decimal(10,2) NOT NULL,
  `new_price` decimal(10,2) NOT NULL,
  `price_change_percentage` decimal(5,2) NOT NULL,
  `reason` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'Motivo da mudança (ex: competitor_price_below)',
  `applied_successfully` tinyint(1) DEFAULT '0',
  `error_message` text COLLATE utf8mb4_unicode_ci,
  `executed_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_rule_id` (`rule_id`),
  KEY `idx_account_id` (`account_id`),
  KEY `idx_item_id` (`item_id`),
  KEY `idx_executed_at` (`executed_at`),
  CONSTRAINT `pricing_rule_executions_ibfk_1` FOREIGN KEY (`rule_id`) REFERENCES `pricing_rules` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Histórico de execuções de regras de precificação';
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `pricing_rules`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `pricing_rules` (
  `id` int NOT NULL AUTO_INCREMENT,
  `account_id` int NOT NULL,
  `name` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `item_id` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'MLB ID do produto',
  `rules` json NOT NULL COMMENT 'Array de regras de precificação',
  `active` tinyint(1) DEFAULT '1',
  `last_evaluation_at` timestamp NULL DEFAULT NULL COMMENT 'Última vez que regras foram avaliadas',
  `last_applied_at` timestamp NULL DEFAULT NULL COMMENT 'Última vez que regra foi aplicada',
  `evaluation_frequency_minutes` int DEFAULT '60' COMMENT 'Frequência de avaliação',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `rule_type` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `priority` int DEFAULT '100',
  `config` json DEFAULT NULL COMMENT 'Rule configuration based on type',
  `items` json DEFAULT NULL COMMENT 'Item IDs to apply rule',
  `categories` json DEFAULT NULL COMMENT 'Category IDs to apply rule',
  `is_active` tinyint(1) DEFAULT '1',
  `last_executed_at` datetime DEFAULT NULL,
  `execution_count` int DEFAULT '0',
  `nome` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `descricao` text COLLATE utf8mb4_unicode_ci,
  `aplica_categoria` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `aplica_marca` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `aplica_item_ids` json DEFAULT NULL,
  `estrategia` varchar(30) COLLATE utf8mb4_unicode_ci DEFAULT 'competitivo',
  `margem_minima` decimal(5,2) DEFAULT '10.00',
  `margem_alvo` decimal(5,2) DEFAULT '20.00',
  `desconto_maximo` decimal(5,2) DEFAULT '30.00',
  `aumento_maximo` decimal(5,2) DEFAULT '15.00',
  `limite_aumento_ranking` decimal(5,2) DEFAULT '8.00',
  `ativo` tinyint(1) DEFAULT '1',
  `execucao_automatica` tinyint(1) DEFAULT '0',
  `intervalo_verificacao` int DEFAULT '24',
  `ultima_execucao` timestamp NULL DEFAULT NULL,
  `description` text COLLATE utf8mb4_unicode_ci,
  `conditions` json DEFAULT NULL,
  `actions` json DEFAULT NULL,
  `applies_to` varchar(20) COLLATE utf8mb4_unicode_ci DEFAULT 'all',
  `item_ids` json DEFAULT NULL,
  `category_ids` json DEFAULT NULL,
  `start_date` datetime DEFAULT NULL,
  `end_date` datetime DEFAULT NULL,
  `scope` varchar(20) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `scope_id` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `params` json DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_account_item` (`account_id`,`item_id`),
  KEY `idx_account_id` (`account_id`),
  KEY `idx_item_id` (`item_id`),
  KEY `idx_active` (`active`),
  KEY `idx_last_evaluation` (`last_evaluation_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Regras de precificação dinâmica configuradas por item';
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `product_costs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `product_costs` (
  `id` int NOT NULL AUTO_INCREMENT,
  `account_id` int NOT NULL,
  `item_id` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'MLB ID do anúncio',
  `sku` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'SKU interno do produto',
  `custo_producao` decimal(12,2) NOT NULL DEFAULT '0.00' COMMENT 'Custo de aquisição/produção',
  `custo_embalagem` decimal(10,2) DEFAULT '0.00' COMMENT 'Embalagem por unidade',
  `custo_etiqueta` decimal(10,2) DEFAULT '0.00' COMMENT 'Etiqueta/tag',
  `custo_frete_entrada` decimal(10,2) DEFAULT '0.00' COMMENT 'Frete de fornecedor',
  `taxa_comissao_ml` decimal(5,2) DEFAULT '0.00' COMMENT '% comissão ML (varia por categoria)',
  `taxa_imposto` decimal(5,2) DEFAULT '0.00' COMMENT '% impostos (Simples/Presumido)',
  `acos_medio` decimal(5,2) DEFAULT '0.00' COMMENT 'Custo médio de Ads %',
  `custo_frete_gratis` decimal(10,2) DEFAULT '0.00' COMMENT 'Custo de frete grátis assumido',
  `margem_minima` decimal(5,2) DEFAULT '10.00' COMMENT 'Margem mínima aceitável %',
  `margem_alvo` decimal(5,2) DEFAULT '20.00' COMMENT 'Margem alvo desejada %',
  `preco_minimo_calculado` decimal(12,2) DEFAULT NULL COMMENT 'Preço mínimo para margem mínima',
  `preco_alvo_calculado` decimal(12,2) DEFAULT NULL COMMENT 'Preço para atingir margem alvo',
  `atualizado_em` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `criado_em` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_account_item` (`account_id`,`item_id`),
  KEY `idx_account_sku` (`account_id`,`sku`),
  KEY `idx_item_id` (`item_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `promotion_performance`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `promotion_performance` (
  `id` int NOT NULL AUTO_INCREMENT,
  `account_id` int NOT NULL,
  `promotion_id` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
  `date` date NOT NULL,
  `sales` int DEFAULT '0',
  `revenue` decimal(12,2) DEFAULT '0.00',
  `discount_given` decimal(12,2) DEFAULT '0.00',
  `conversion_rate` decimal(5,2) DEFAULT '0.00',
  `data` json DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_promotion_date` (`account_id`,`promotion_id`,`date`),
  KEY `idx_account` (`account_id`),
  KEY `idx_promotion` (`promotion_id`),
  KEY `idx_date` (`date`),
  KEY `idx_sales` (`sales`),
  KEY `idx_revenue` (`revenue`),
  CONSTRAINT `promotion_performance_ibfk_1` FOREIGN KEY (`account_id`) REFERENCES `ml_accounts` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `promotion_simulations`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `promotion_simulations` (
  `id` int NOT NULL AUTO_INCREMENT,
  `account_id` int NOT NULL,
  `item_id` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
  `preco_original` decimal(12,2) NOT NULL,
  `titulo` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `desconto_percentual` decimal(5,2) NOT NULL,
  `preco_promocional` decimal(12,2) NOT NULL,
  `custo_total` decimal(12,2) DEFAULT NULL,
  `margem_promocao` decimal(6,2) DEFAULT NULL,
  `lucro_unitario_promocao` decimal(12,2) DEFAULT NULL,
  `desconto_maximo_seguro` decimal(5,2) DEFAULT NULL COMMENT 'Desconto máximo mantendo margem 5%',
  `viavel` tinyint(1) DEFAULT '1',
  `alerta` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `vendas_estimadas_aumento` int DEFAULT NULL COMMENT 'Aumento estimado de vendas %',
  `receita_projetada` decimal(14,2) DEFAULT NULL,
  `lucro_projetado` decimal(14,2) DEFAULT NULL,
  `aplicada` tinyint(1) DEFAULT '0',
  `aplicada_em` timestamp NULL DEFAULT NULL,
  `criado_em` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_account_item` (`account_id`,`item_id`),
  KEY `idx_account_date` (`account_id`,`criado_em`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `push_notification_logs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `push_notification_logs` (
  `id` int NOT NULL AUTO_INCREMENT,
  `user_id` int NOT NULL,
  `subscription_id` int DEFAULT NULL,
  `title` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `body` text COLLATE utf8mb4_unicode_ci,
  `payload` json DEFAULT NULL,
  `status` enum('pending','sent','failed','expired') COLLATE utf8mb4_unicode_ci DEFAULT 'pending',
  `error_message` text COLLATE utf8mb4_unicode_ci,
  `sent_at` datetime DEFAULT NULL,
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_user_id` (`user_id`),
  KEY `idx_subscription_id` (`subscription_id`),
  KEY `idx_status` (`status`),
  KEY `idx_sent_at` (`sent_at`),
  CONSTRAINT `push_notification_logs_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `push_notification_logs_ibfk_2` FOREIGN KEY (`subscription_id`) REFERENCES `push_subscriptions` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `push_notification_queue`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `push_notification_queue` (
  `id` bigint NOT NULL AUTO_INCREMENT,
  `account_id` int NOT NULL,
  `config` json NOT NULL,
  `event_type` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
  `data` json NOT NULL,
  `severity` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL,
  `status` enum('pending','processing','sent','failed') COLLATE utf8mb4_unicode_ci DEFAULT 'pending',
  `attempts` int DEFAULT '0',
  `last_attempt_at` datetime DEFAULT NULL,
  `error_message` text COLLATE utf8mb4_unicode_ci,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `processed_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_status` (`status`),
  KEY `idx_created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `push_subscriptions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `push_subscriptions` (
  `id` int NOT NULL AUTO_INCREMENT,
  `user_id` int NOT NULL,
  `endpoint` varchar(500) COLLATE utf8mb4_unicode_ci NOT NULL,
  `p256dh_key` text COLLATE utf8mb4_unicode_ci,
  `auth_key` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `user_agent` varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `last_notified_at` datetime DEFAULT NULL,
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `endpoint` (`endpoint`),
  KEY `idx_user_id` (`user_id`),
  KEY `idx_endpoint` (`endpoint`(191)),
  KEY `idx_last_notified` (`last_notified_at`),
  CONSTRAINT `push_subscriptions_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `pwa_settings`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `pwa_settings` (
  `id` int NOT NULL AUTO_INCREMENT,
  `user_id` int NOT NULL,
  `push_enabled` tinyint(1) DEFAULT '1',
  `push_sales` tinyint(1) DEFAULT '1',
  `push_stock` tinyint(1) DEFAULT '1',
  `push_alerts` tinyint(1) DEFAULT '1',
  `push_system` tinyint(1) DEFAULT '1',
  `offline_mode` tinyint(1) DEFAULT '1',
  `installed` tinyint(1) DEFAULT '0',
  `install_date` datetime DEFAULT NULL,
  `last_sync_at` datetime DEFAULT NULL,
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `user_id` (`user_id`),
  KEY `idx_user_id` (`user_id`),
  CONSTRAINT `pwa_settings_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `q_table`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `q_table` (
  `id` int NOT NULL AUTO_INCREMENT,
  `state_hash` varchar(64) COLLATE utf8mb4_general_ci NOT NULL,
  `action` varchar(100) COLLATE utf8mb4_general_ci NOT NULL,
  `q_value` decimal(10,6) NOT NULL DEFAULT '0.000000',
  `account_id` varchar(100) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `update_count` int DEFAULT '1',
  `last_reward` decimal(10,6) DEFAULT '0.000000',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_state_action` (`state_hash`,`action`,`account_id`),
  KEY `idx_state_hash` (`state_hash`),
  KEY `idx_account_id` (`account_id`),
  KEY `idx_updated_at` (`updated_at`),
  KEY `idx_q_table_composite` (`account_id`,`state_hash`,`q_value` DESC)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci ROW_FORMAT=COMPRESSED KEY_BLOCK_SIZE=8;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET collation_connection  = utf8mb4_0900_ai_ci */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION' */ ;
DELIMITER ;;
/*!50003 CREATE*/  /*!50003 TRIGGER `update_q_table_timestamp` BEFORE UPDATE ON `q_table` FOR EACH ROW BEGIN
    SET NEW.updated_at = CURRENT_TIMESTAMP;
END */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
DROP TABLE IF EXISTS `query_log`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `query_log` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `sql_text` text COLLATE utf8mb4_unicode_ci NOT NULL,
  `params` json DEFAULT NULL,
  `duration` decimal(10,6) NOT NULL COMMENT 'Duração em segundos',
  `row_count` int unsigned DEFAULT '0',
  `error` text COLLATE utf8mb4_unicode_ci,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_duration` (`duration`),
  KEY `idx_created_at` (`created_at`),
  KEY `idx_slow_queries` (`created_at`,`duration`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `questions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `questions` (
  `id` int NOT NULL AUTO_INCREMENT,
  `ml_question_id` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
  `account_id` int NOT NULL,
  `item_id` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
  `seller_id` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `buyer_id` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `question_text` text COLLATE utf8mb4_unicode_ci,
  `answer_text` text COLLATE utf8mb4_unicode_ci,
  `status` enum('UNANSWERED','ANSWERED','CLOSED_UNANSWERED','UNDER_REVIEW','BANNED') COLLATE utf8mb4_unicode_ci DEFAULT 'UNANSWERED',
  `date_created` datetime DEFAULT NULL,
  `answer_date` datetime DEFAULT NULL,
  `data` json DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `ml_question_id` (`ml_question_id`),
  KEY `idx_account_id` (`account_id`),
  KEY `idx_item_id` (`item_id`),
  KEY `idx_status` (`status`),
  KEY `idx_date_created` (`date_created`)
) ENGINE=InnoDB AUTO_INCREMENT=472266 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `rate_limit_requests`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `rate_limit_requests` (
  `id` bigint NOT NULL AUTO_INCREMENT,
  `identifier` varchar(255) NOT NULL,
  `operation` varchar(100) NOT NULL,
  `allowed` tinyint(1) NOT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `user_agent` text,
  `context` json DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_identifier_created` (`identifier`,`created_at`),
  KEY `idx_operation_created` (`operation`,`created_at`),
  KEY `idx_allowed_created` (`allowed`,`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `rate_limits`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `rate_limits` (
  `id` int NOT NULL AUTO_INCREMENT,
  `ip_address` varchar(45) COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_ip_created` (`ip_address`,`created_at`)
) ENGINE=InnoDB AUTO_INCREMENT=268815 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `realtime_alerts`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `realtime_alerts` (
  `id` int NOT NULL AUTO_INCREMENT,
  `account_id` int NOT NULL,
  `alert_type` enum('keyword','score','competitor','system') COLLATE utf8mb4_unicode_ci NOT NULL,
  `message` varchar(500) COLLATE utf8mb4_unicode_ci NOT NULL,
  `data_json` json DEFAULT NULL,
  `sent` tinyint(1) DEFAULT '0',
  `read_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_account_id` (`account_id`),
  KEY `idx_alert_type` (`alert_type`),
  KEY `idx_sent` (`sent`),
  KEY `idx_created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `realtime_market_signals`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `realtime_market_signals` (
  `id` int NOT NULL AUTO_INCREMENT,
  `signal_type` enum('trend_change','price_anomaly','demand_spike','competitor_action','algorithm_shift','seasonal_transition') COLLATE utf8mb4_general_ci NOT NULL,
  `signal_source` varchar(100) COLLATE utf8mb4_general_ci NOT NULL,
  `severity` enum('info','warning','critical') COLLATE utf8mb4_general_ci NOT NULL,
  `confidence` decimal(5,4) NOT NULL,
  `affected_items` json DEFAULT NULL,
  `market_conditions` json DEFAULT NULL,
  `recommended_actions` json DEFAULT NULL,
  `signal_data` json DEFAULT NULL,
  `processed` tinyint(1) DEFAULT '0',
  `processing_result` json DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `expires_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_signal_type_severity` (`signal_type`,`severity`),
  KEY `idx_confidence` (`confidence` DESC),
  KEY `idx_processed` (`processed`),
  KEY `idx_created_at` (`created_at`),
  KEY `idx_expires_at` (`expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `realtime_notifications`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `realtime_notifications` (
  `id` bigint NOT NULL AUTO_INCREMENT,
  `account_id` int NOT NULL,
  `type` enum('order','question','message','alert','price_change') COLLATE utf8mb4_unicode_ci NOT NULL,
  `title` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `message` text COLLATE utf8mb4_unicode_ci NOT NULL,
  `data` json DEFAULT NULL,
  `priority` enum('low','normal','high','urgent') COLLATE utf8mb4_unicode_ci DEFAULT 'normal',
  `is_read` tinyint(1) DEFAULT '0',
  `is_pushed` tinyint(1) DEFAULT '0',
  `sound_played` tinyint(1) DEFAULT '0',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `read_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_account_unread` (`account_id`,`is_read`),
  KEY `idx_account_pushed` (`account_id`,`is_pushed`),
  KEY `idx_created` (`created_at`)
) ENGINE=InnoDB AUTO_INCREMENT=7 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `refresh_tokens`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `refresh_tokens` (
  `id` int NOT NULL AUTO_INCREMENT,
  `user_id` int NOT NULL,
  `selector` varchar(64) COLLATE utf8mb4_unicode_ci NOT NULL,
  `hashed_validator` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `device_info` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `ip_address` varchar(45) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `expires_at` datetime NOT NULL,
  `revoked` tinyint(1) DEFAULT '0',
  `replaced_by` int DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_selector` (`selector`),
  KEY `idx_user` (`user_id`)
) ENGINE=InnoDB AUTO_INCREMENT=120 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `remember_tokens`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `remember_tokens` (
  `id` int NOT NULL AUTO_INCREMENT,
  `user_id` int NOT NULL,
  `selector` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `hashed_validator` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `expires_at` datetime NOT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_selector` (`selector`),
  KEY `idx_user_id` (`user_id`),
  CONSTRAINT `remember_tokens_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=14 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `replay_buffer`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `replay_buffer` (
  `id` int NOT NULL AUTO_INCREMENT,
  `account_id` varchar(100) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `network_id` int DEFAULT NULL,
  `experience_data` json NOT NULL,
  `priority` decimal(10,6) DEFAULT '1.000000',
  `sample_count` int DEFAULT '0',
  `last_sampled` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_account_priority` (`account_id`,`priority` DESC),
  KEY `idx_created_at` (`created_at`),
  KEY `idx_network_id` (`network_id`),
  CONSTRAINT `replay_buffer_ibfk_1` FOREIGN KEY (`network_id`) REFERENCES `neural_networks` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `reputation_history`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `reputation_history` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `account_id` int NOT NULL,
  `date` date NOT NULL,
  `level_id` varchar(20) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `power_seller_status` varchar(20) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `thermometer` int DEFAULT '0',
  `total_transactions` int DEFAULT '0',
  `completed_transactions` int DEFAULT '0',
  `cancellations_rate` decimal(5,2) DEFAULT '0.00',
  `claims_rate` decimal(5,2) DEFAULT '0.00',
  `delayed_handling_time_rate` decimal(5,2) DEFAULT '0.00',
  `positive_rating` decimal(5,2) DEFAULT '0.00',
  `neutral_rating` decimal(5,2) DEFAULT '0.00',
  `negative_rating` decimal(5,2) DEFAULT '0.00',
  `average_rating` decimal(3,2) DEFAULT '0.00',
  `data` json DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_account_date` (`account_id`,`date`),
  KEY `idx_account_id` (`account_id`),
  KEY `idx_date` (`date`),
  KEY `idx_level_id` (`level_id`),
  CONSTRAINT `reputation_history_ibfk_1` FOREIGN KEY (`account_id`) REFERENCES `ml_accounts` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `returns`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `returns` (
  `id` bigint NOT NULL AUTO_INCREMENT,
  `ml_order_id` bigint NOT NULL,
  `claim_id` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `status` enum('WAITING_ARRIVAL','RECEIVED','CHECKING','RESTOCKED','DISCARDED','RETURNED_TO_BUYER') COLLATE utf8mb4_unicode_ci DEFAULT 'WAITING_ARRIVAL',
  `condition_rating` tinyint DEFAULT NULL,
  `inspection_notes` text COLLATE utf8mb4_unicode_ci,
  `inspector_id` int DEFAULT NULL,
  `sku` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `quantity` int DEFAULT '1',
  `refunded_amount` decimal(10,2) DEFAULT '0.00',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `reentry_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_order` (`ml_order_id`),
  KEY `idx_status` (`status`),
  KEY `idx_sku` (`sku`)
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `scheduled_optimizations`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `scheduled_optimizations` (
  `id` int NOT NULL AUTO_INCREMENT,
  `item_id` varchar(100) COLLATE utf8mb4_general_ci NOT NULL,
  `schedule_data` json DEFAULT NULL,
  `status` enum('scheduled','executing','completed','failed','cancelled') COLLATE utf8mb4_general_ci DEFAULT 'scheduled',
  `scheduled_time` timestamp NOT NULL,
  `execution_time` timestamp NULL DEFAULT NULL,
  `execution_id` varchar(100) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `account_id` varchar(100) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_item_status` (`item_id`,`status`),
  KEY `idx_scheduled_time` (`scheduled_time`),
  KEY `idx_account_id` (`account_id`),
  KEY `idx_execution_id` (`execution_id`),
  CONSTRAINT `scheduled_optimizations_ibfk_1` FOREIGN KEY (`execution_id`) REFERENCES `autopilot_execution_sessions` (`execution_id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `scheduled_reports`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `scheduled_reports` (
  `id` int NOT NULL AUTO_INCREMENT,
  `user_id` int NOT NULL,
  `report_type` enum('sales','dashboard','orders','market','weekly_performance') COLLATE utf8mb4_unicode_ci NOT NULL,
  `frequency` enum('daily','weekly','monthly') COLLATE utf8mb4_unicode_ci NOT NULL,
  `day_of_week` tinyint DEFAULT NULL COMMENT '0-6 para semanal',
  `day_of_month` tinyint DEFAULT NULL COMMENT '1-31 para mensal',
  `time` time DEFAULT '09:00:00',
  `last_sent_at` timestamp NULL DEFAULT NULL,
  `next_send_at` timestamp NULL DEFAULT NULL,
  `is_active` tinyint(1) DEFAULT '1',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_user_id` (`user_id`),
  KEY `idx_next_send_at` (`next_send_at`),
  KEY `idx_is_active` (`is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `schedules`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `schedules` (
  `id` int NOT NULL AUTO_INCREMENT,
  `user_id` int NOT NULL,
  `account_id` int DEFAULT NULL,
  `name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `type` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
  `schedule_cron` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `schedule_time` time DEFAULT NULL,
  `schedule_days` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `config` json DEFAULT NULL,
  `is_active` tinyint(1) DEFAULT '1',
  `last_run` datetime DEFAULT NULL,
  `next_run` datetime DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_user` (`user_id`),
  KEY `idx_active` (`is_active`),
  KEY `idx_next_run` (`next_run`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `security_audit_log`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `security_audit_log` (
  `id` int NOT NULL AUTO_INCREMENT,
  `event_type` varchar(100) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `ip_address` varchar(45) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `user_agent` text COLLATE utf8mb4_general_ci,
  `details` text COLLATE utf8mb4_general_ci,
  `severity` varchar(20) COLLATE utf8mb4_general_ci DEFAULT 'info',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=321 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `security_events`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `security_events` (
  `id` int NOT NULL AUTO_INCREMENT,
  `event_type` varchar(50) COLLATE utf8mb4_general_ci NOT NULL,
  `severity` varchar(20) COLLATE utf8mb4_general_ci DEFAULT 'info',
  `ip_address` varchar(45) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `user_agent` text COLLATE utf8mb4_general_ci,
  `user_id` int DEFAULT NULL,
  `details` text COLLATE utf8mb4_general_ci,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_event_type` (`event_type`),
  KEY `idx_severity` (`severity`),
  KEY `idx_created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `seller_catalog_snapshots`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `seller_catalog_snapshots` (
  `id` int NOT NULL AUTO_INCREMENT,
  `seller_id` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `filters_hash` varchar(64) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'SHA-256 do JSON serializado dos filtros',
  `filters` json DEFAULT NULL COMMENT 'Filtros originais (categoria, marca, keyword, etc.)',
  `snapshot_data` longtext COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'JSON do resultado completo (items + facets + summary)',
  `item_count` int NOT NULL DEFAULT '0' COMMENT 'Número de itens no snapshot',
  `expires_at` datetime NOT NULL COMMENT 'TTL do cache',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_seller_filters` (`seller_id`,`filters_hash`),
  KEY `idx_expires_at` (`expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Cache de catálogos de sellers para grandes volumes';
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `seo_ab_metrics`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `seo_ab_metrics` (
  `id` int NOT NULL AUTO_INCREMENT,
  `test_id` int NOT NULL,
  `variant` varchar(1) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'a or b',
  `date` date NOT NULL,
  `views` int DEFAULT '0',
  `visits` int DEFAULT '0',
  `sales` int DEFAULT '0',
  `revenue` decimal(10,2) DEFAULT '0.00',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_test_variant_date` (`test_id`,`variant`,`date`),
  KEY `idx_test_id` (`test_id`),
  KEY `idx_date` (`date`),
  CONSTRAINT `seo_ab_metrics_ibfk_1` FOREIGN KEY (`test_id`) REFERENCES `ai_ab_tests` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='A/B test metrics tracking';
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `seo_ab_tests`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `seo_ab_tests` (
  `id` int NOT NULL AUTO_INCREMENT,
  `account_id` int NOT NULL,
  `test_name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `item_id` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
  `type` enum('title','price','picture') COLLATE utf8mb4_unicode_ci NOT NULL,
  `variant_a_data` json NOT NULL COMMENT 'Original/Control variant',
  `variant_b_data` json NOT NULL COMMENT 'Optimized variant',
  `status` enum('active','running','paused','completed','stopped') COLLATE utf8mb4_unicode_ci DEFAULT 'active',
  `winner` varchar(1) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'a or b',
  `confidence_level` int DEFAULT '95',
  `is_significant` tinyint(1) DEFAULT '0',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `started_at` timestamp NULL DEFAULT NULL,
  `ended_at` timestamp NULL DEFAULT NULL,
  `start_date` timestamp NULL DEFAULT NULL,
  `end_date` timestamp NULL DEFAULT NULL,
  `duration_days` int DEFAULT '14',
  `winner_variant` enum('A','B') COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `confidence_score` decimal(5,2) DEFAULT '0.00',
  `auto_apply_winner` tinyint(1) DEFAULT '1',
  PRIMARY KEY (`id`),
  KEY `idx_item_id` (`item_id`),
  KEY `idx_account` (`account_id`),
  KEY `idx_status` (`status`),
  KEY `idx_created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='A/B test definitions';
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `seo_analysis_cache`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `seo_analysis_cache` (
  `item_id` varchar(64) NOT NULL,
  `account_id` int NOT NULL,
  `category_id` varchar(32) DEFAULT NULL,
  `overall_score` decimal(6,2) DEFAULT '0.00',
  `strategies_json` longtext,
  `suggestions_json` longtext,
  `title_analysis_json` longtext,
  `description_analysis_json` longtext,
  `item_title` varchar(255) DEFAULT NULL,
  `item_price` decimal(10,2) DEFAULT NULL,
  `analysis_version` varchar(20) DEFAULT NULL,
  `expires_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL,
  `updated_at` datetime NOT NULL,
  PRIMARY KEY (`item_id`,`account_id`),
  KEY `idx_seo_cache_account` (`account_id`),
  KEY `idx_cache_cleanup` (`expires_at`,`updated_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `seo_analysis_jobs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `seo_analysis_jobs` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `account_id` int unsigned NOT NULL,
  `job_type` enum('single','batch','scheduled') COLLATE utf8mb4_unicode_ci DEFAULT 'single',
  `status` enum('pending','running','completed','failed') COLLATE utf8mb4_unicode_ci DEFAULT 'pending',
  `item_ids_json` json NOT NULL,
  `total_items` int unsigned DEFAULT '0',
  `processed_items` int unsigned DEFAULT '0',
  `failed_items` int unsigned DEFAULT '0',
  `results_json` json DEFAULT NULL,
  `error_message` text COLLATE utf8mb4_unicode_ci,
  `started_at` timestamp NULL DEFAULT NULL,
  `completed_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_account_status` (`account_id`,`status`),
  KEY `idx_created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `seo_audits`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `seo_audits` (
  `id` int NOT NULL AUTO_INCREMENT,
  `item_id` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
  `account_id` int NOT NULL,
  `audit_date` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `overall_score` tinyint unsigned NOT NULL DEFAULT '0' COMMENT 'Overall SEO score 0-100',
  `title_score` tinyint unsigned NOT NULL DEFAULT '0',
  `description_score` tinyint unsigned NOT NULL DEFAULT '0',
  `attributes_score` tinyint unsigned NOT NULL DEFAULT '0',
  `images_score` tinyint unsigned NOT NULL DEFAULT '0',
  `pricing_score` tinyint unsigned NOT NULL DEFAULT '0',
  `category_score` tinyint unsigned NOT NULL DEFAULT '0',
  `required_attributes_pct` tinyint unsigned DEFAULT '0' COMMENT 'Percentage of required attributes filled',
  `optional_attributes_pct` tinyint unsigned DEFAULT '0' COMMENT 'Percentage of optional attributes filled',
  `hidden_attributes_pct` tinyint unsigned DEFAULT '0' COMMENT 'Percentage of hidden attributes filled',
  `recommendations` json DEFAULT NULL COMMENT 'Array of recommendation objects with type, priority, message, impact',
  `processing_time_ms` int unsigned DEFAULT NULL COMMENT 'Time taken to complete audit in milliseconds',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_item_id` (`item_id`),
  KEY `idx_account_id` (`account_id`),
  KEY `idx_audit_date` (`audit_date`),
  KEY `idx_overall_score` (`overall_score`),
  CONSTRAINT `seo_audits_ibfk_1` FOREIGN KEY (`account_id`) REFERENCES `ml_accounts` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Stores SEO audit results for listings';
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `seo_automation_config`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `seo_automation_config` (
  `id` int NOT NULL AUTO_INCREMENT,
  `account_id` int NOT NULL,
  `mode` enum('manual','semi_automatic','automatic') COLLATE utf8mb4_unicode_ci DEFAULT 'manual',
  `enabled` tinyint(1) DEFAULT '0',
  `max_changes_per_item_per_day` tinyint unsigned DEFAULT '10',
  `max_changes_per_account_per_day` smallint unsigned DEFAULT '100',
  `cooldown_hours` tinyint unsigned DEFAULT '24' COMMENT 'Hours between changes to same item',
  `auto_apply_title` tinyint(1) DEFAULT '0',
  `auto_apply_description` tinyint(1) DEFAULT '0',
  `auto_apply_attributes` tinyint(1) DEFAULT '0',
  `auto_apply_images` tinyint(1) DEFAULT '0',
  `min_confidence_score` decimal(3,2) DEFAULT '0.80' COMMENT 'Minimum AI confidence for auto-apply',
  `min_seo_score_for_auto` tinyint unsigned DEFAULT '50' COMMENT 'Only auto-optimize items below this score',
  `notify_on_apply` tinyint(1) DEFAULT '1',
  `notify_on_error` tinyint(1) DEFAULT '1',
  `notification_email` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `audit_schedule` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT 'daily' COMMENT 'daily, weekly, manual',
  `audit_time` time DEFAULT '02:00:00' COMMENT 'Preferred time for audits',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `last_audit_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `account_id` (`account_id`),
  KEY `idx_account_id` (`account_id`),
  CONSTRAINT `seo_automation_config_ibfk_1` FOREIGN KEY (`account_id`) REFERENCES `ml_accounts` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Automation configuration per account';
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `seo_autopilot_config`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `seo_autopilot_config` (
  `id` int NOT NULL AUTO_INCREMENT,
  `account_id` int NOT NULL,
  `config` json NOT NULL,
  `last_run` timestamp NULL DEFAULT NULL,
  `next_run` timestamp NULL DEFAULT NULL,
  `total_runs` int DEFAULT '0',
  `total_optimizations` int DEFAULT '0',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `account_id` (`account_id`),
  KEY `idx_account` (`account_id`),
  KEY `idx_next_run` (`next_run`)
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `seo_autopilot_runs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `seo_autopilot_runs` (
  `id` int NOT NULL AUTO_INCREMENT,
  `account_id` int NOT NULL,
  `status` enum('scheduled','running','completed','failed') DEFAULT 'scheduled',
  `items_analyzed` int DEFAULT '0',
  `items_optimized` int DEFAULT '0',
  `items_skipped` int DEFAULT '0',
  `items_failed` int DEFAULT '0',
  `avg_score_before` decimal(5,2) DEFAULT '0.00',
  `avg_score_after` decimal(5,2) DEFAULT '0.00',
  `details` json DEFAULT NULL,
  `started_at` timestamp NULL DEFAULT NULL,
  `completed_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_account` (`account_id`),
  KEY `idx_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `seo_bulk_jobs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `seo_bulk_jobs` (
  `id` int NOT NULL AUTO_INCREMENT,
  `account_id` int NOT NULL,
  `job_type` enum('full','title','description','attributes') DEFAULT 'full',
  `status` enum('pending','running','completed','failed') DEFAULT 'pending',
  `total_items` int DEFAULT '0',
  `processed_items` int DEFAULT '0',
  `successful_items` int DEFAULT '0',
  `failed_items` int DEFAULT '0',
  `item_ids` json DEFAULT NULL,
  `results` json DEFAULT NULL,
  `options` json DEFAULT NULL,
  `started_at` timestamp NULL DEFAULT NULL,
  `completed_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_account` (`account_id`),
  KEY `idx_status` (`status`)
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `seo_category_benchmarks`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `seo_category_benchmarks` (
  `id` int NOT NULL AUTO_INCREMENT,
  `account_id` int NOT NULL,
  `category_id` varchar(50) NOT NULL,
  `average_score` decimal(5,2) DEFAULT NULL,
  `top_10_percent_score` decimal(5,2) DEFAULT NULL,
  `sample_size` int DEFAULT NULL,
  `last_updated` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_category` (`account_id`,`category_id`)
) ENGINE=InnoDB AUTO_INCREMENT=37 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `seo_category_config`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `seo_category_config` (
  `id` int NOT NULL AUTO_INCREMENT,
  `category_id` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL,
  `config_key` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'Chave da configuração',
  `config_value` json NOT NULL COMMENT 'Valor em JSON',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_category_key` (`category_id`,`config_key`),
  KEY `idx_category` (`category_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Configurações customizadas de SEO por categoria';
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `seo_competitors`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `seo_competitors` (
  `id` int NOT NULL AUTO_INCREMENT,
  `item_id` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'Our item ID',
  `competitor_item_id` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'Competitor item ID',
  `account_id` int NOT NULL,
  `title` varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `price` decimal(10,2) DEFAULT NULL,
  `currency_id` varchar(10) COLLATE utf8mb4_unicode_ci DEFAULT 'BRL',
  `condition_type` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `sold_quantity` int unsigned DEFAULT '0',
  `available_quantity` int unsigned DEFAULT '0',
  `image_count` tinyint unsigned DEFAULT '0',
  `attribute_count` tinyint unsigned DEFAULT '0',
  `has_free_shipping` tinyint(1) DEFAULT '0',
  `listing_type` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'gold_special, gold_pro, free, etc.',
  `relevance_score` decimal(5,2) DEFAULT NULL COMMENT 'Similarity score to our item',
  `rank_position` int unsigned DEFAULT NULL COMMENT 'Position in search results',
  `data` json DEFAULT NULL COMMENT 'Complete item data from ML API',
  `discovered_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `last_updated` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `is_active` tinyint(1) DEFAULT '1' COMMENT 'Whether competitor is still active',
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_item_competitor` (`item_id`,`competitor_item_id`),
  KEY `idx_item_id` (`item_id`),
  KEY `idx_competitor_item_id` (`competitor_item_id`),
  KEY `idx_account_id` (`account_id`),
  KEY `idx_relevance_score` (`relevance_score`),
  KEY `idx_discovered_at` (`discovered_at`),
  KEY `idx_is_active` (`is_active`),
  CONSTRAINT `seo_competitors_ibfk_1` FOREIGN KEY (`account_id`) REFERENCES `ml_accounts` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=82 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Stores competitor data for benchmarking';
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `seo_gsc_auth`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `seo_gsc_auth` (
  `id` int NOT NULL AUTO_INCREMENT,
  `account_id` int NOT NULL,
  `access_token` text NOT NULL,
  `refresh_token` text NOT NULL,
  `expires_at` datetime NOT NULL,
  `property_id` varchar(255) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `account_id` (`account_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `seo_hidden_attributes`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `seo_hidden_attributes` (
  `id` int NOT NULL AUTO_INCREMENT,
  `item_id` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
  `account_id` int NOT NULL,
  `category_id` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
  `attribute_id` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'ML attribute ID',
  `attribute_name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'Human-readable attribute name',
  `attribute_type` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'string, number, boolean, list, etc.',
  `frequency` tinyint unsigned NOT NULL COMMENT 'Percentage frequency in top competitors (0-100)',
  `competitor_count` int unsigned NOT NULL COMMENT 'Number of competitors analyzed',
  `impact_level` enum('high','medium','low') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'medium',
  `suggested_values` json DEFAULT NULL COMMENT 'Array of suggested values from competitors',
  `value_distribution` json DEFAULT NULL COMMENT 'Frequency distribution of values',
  `requires_validation` tinyint(1) DEFAULT '1' COMMENT 'Whether human validation is required',
  `is_technical` tinyint(1) DEFAULT '0' COMMENT 'Whether this is a technical specification',
  `status` enum('detected','applied','rejected','pending') COLLATE utf8mb4_unicode_ci DEFAULT 'detected',
  `applied_value` varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'Value that was applied (if any)',
  `applied_at` timestamp NULL DEFAULT NULL,
  `applied_by` int DEFAULT NULL COMMENT 'User ID who applied the value',
  `detected_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_item_attribute` (`item_id`,`attribute_id`),
  KEY `idx_item_id` (`item_id`),
  KEY `idx_account_id` (`account_id`),
  KEY `idx_category_id` (`category_id`),
  KEY `idx_impact_level` (`impact_level`),
  KEY `idx_status` (`status`),
  KEY `idx_detected_at` (`detected_at`),
  CONSTRAINT `seo_hidden_attributes_ibfk_1` FOREIGN KEY (`account_id`) REFERENCES `ml_accounts` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Tracks hidden attributes detected from competitor analysis';
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `seo_item_scores`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `seo_item_scores` (
  `id` int NOT NULL AUTO_INCREMENT,
  `account_id` int NOT NULL,
  `item_id` varchar(50) NOT NULL,
  `score_date` date NOT NULL,
  `overall_score` int DEFAULT '0',
  `title_score` int DEFAULT '0',
  `description_score` int DEFAULT '0',
  `attributes_score` int DEFAULT '0',
  `images_score` int DEFAULT '0',
  `visibility_score` int DEFAULT '0',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_item_date` (`item_id`,`score_date`),
  KEY `idx_account` (`account_id`),
  KEY `idx_item` (`item_id`),
  KEY `idx_date` (`score_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `seo_keyword_cache`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `seo_keyword_cache` (
  `id` int NOT NULL AUTO_INCREMENT,
  `category_id` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL,
  `base_keyword` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'Keyword base da busca',
  `keyword` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'Keyword encontrada',
  `type` enum('core','suporte','tecnica','contexto','trending','autocomplete','competitor') COLLATE utf8mb4_unicode_ci DEFAULT 'core',
  `weight` decimal(3,2) DEFAULT '1.00',
  `source` enum('database','ml_api','ai','unknown') COLLATE utf8mb4_unicode_ci DEFAULT 'unknown',
  `is_valid` tinyint(1) DEFAULT '1',
  `expires_at` timestamp NULL DEFAULT NULL COMMENT 'Quando o cache expira',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_category_base_keyword` (`category_id`,`base_keyword`(100),`keyword`(100)),
  KEY `idx_category` (`category_id`),
  KEY `idx_valid_expires` (`is_valid`,`expires_at`),
  KEY `idx_type` (`type`),
  KEY `idx_source` (`source`)
) ENGINE=InnoDB AUTO_INCREMENT=155 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Cache de keywords da arquitetura híbrida';
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `seo_keyword_performance`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `seo_keyword_performance` (
  `id` int NOT NULL AUTO_INCREMENT,
  `category_id` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL,
  `keyword` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `impressions` int DEFAULT '0',
  `clicks` int DEFAULT '0',
  `conversions` int DEFAULT '0',
  `click_rate` decimal(5,2) DEFAULT '0.00' COMMENT 'CTR em %',
  `conversion_rate` decimal(5,2) DEFAULT '0.00' COMMENT 'Taxa de conversão em %',
  `recorded_at` date NOT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_category_keyword_date` (`category_id`,`keyword`(100),`recorded_at`),
  KEY `idx_category` (`category_id`),
  KEY `idx_recorded_at` (`recorded_at`),
  KEY `idx_performance` (`click_rate`,`conversion_rate`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Performance histórica de keywords para scoring';
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `seo_killer_settings`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `seo_killer_settings` (
  `account_id` int NOT NULL,
  `settings` longtext COLLATE utf8mb4_unicode_ci,
  `updated_at` datetime DEFAULT NULL,
  PRIMARY KEY (`account_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `seo_monitoring_schedule`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `seo_monitoring_schedule` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `item_id` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
  `account_id` int unsigned DEFAULT NULL,
  `interval_days` tinyint unsigned NOT NULL DEFAULT '7',
  `next_check` datetime NOT NULL,
  `last_checked` datetime DEFAULT NULL,
  `status` enum('active','paused','error') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'active',
  `priority` enum('low','normal','high') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'normal',
  `last_result` json DEFAULT NULL,
  `error_message` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_item_id` (`item_id`),
  KEY `idx_status_next_check` (`status`,`next_check`),
  KEY `idx_account_status` (`account_id`,`status`)
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `seo_optimization_events`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `seo_optimization_events` (
  `id` int NOT NULL AUTO_INCREMENT,
  `account_id` int NOT NULL,
  `item_id` varchar(50) NOT NULL,
  `optimization_type` enum('title','description','attributes','full') NOT NULL,
  `old_value` text,
  `new_value` text,
  `score_before` int DEFAULT '0',
  `score_after` int DEFAULT '0',
  `optimized_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_account` (`account_id`),
  KEY `idx_item` (`item_id`),
  KEY `idx_date` (`optimized_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `seo_optimization_history`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `seo_optimization_history` (
  `id` int NOT NULL AUTO_INCREMENT,
  `item_id` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
  `account_id` int NOT NULL,
  `version` int unsigned NOT NULL COMMENT 'Version number for this item',
  `change_type` enum('title','description','attributes','images','price','category','bulk') COLLATE utf8mb4_unicode_ci NOT NULL,
  `changed_by` enum('user','ai','automation') COLLATE utf8mb4_unicode_ci NOT NULL,
  `user_id` int DEFAULT NULL COMMENT 'User who made the change (if applicable)',
  `before_data` json NOT NULL COMMENT 'State before change',
  `after_data` json NOT NULL COMMENT 'State after change',
  `diff` text COLLATE utf8mb4_unicode_ci COMMENT 'Human-readable diff description',
  `estimated_impact` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'Estimated impact description',
  `actual_impact` json DEFAULT NULL COMMENT 'Measured impact after change (visits, conversions, etc.)',
  `can_rollback` tinyint(1) DEFAULT '1',
  `rolled_back` tinyint(1) DEFAULT '0',
  `rolled_back_at` timestamp NULL DEFAULT NULL,
  `rollback_reason` text COLLATE utf8mb4_unicode_ci,
  `applied_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `snapshot_path` varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'Path to full JSON snapshot file',
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_item_version` (`item_id`,`version`),
  KEY `idx_item_id` (`item_id`),
  KEY `idx_account_id` (`account_id`),
  KEY `idx_version` (`version`),
  KEY `idx_change_type` (`change_type`),
  KEY `idx_changed_by` (`changed_by`),
  KEY `idx_applied_at` (`applied_at`),
  KEY `idx_can_rollback` (`can_rollback`),
  CONSTRAINT `seo_optimization_history_ibfk_1` FOREIGN KEY (`account_id`) REFERENCES `ml_accounts` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=303 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Version control and rollback for all optimizations';
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `seo_optimizations`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `seo_optimizations` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `account_id` int unsigned NOT NULL,
  `item_id` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
  `optimization_type` enum('title','description','attributes','images','price','shipping','full') COLLATE utf8mb4_unicode_ci DEFAULT 'full',
  `score_before` decimal(5,2) DEFAULT '0.00',
  `score_after` decimal(5,2) DEFAULT '0.00',
  `score_improvement` decimal(5,2) GENERATED ALWAYS AS ((`score_after` - `score_before`)) STORED,
  `views_before` int unsigned DEFAULT '0',
  `views_after` int unsigned DEFAULT '0',
  `views_increase` int GENERATED ALWAYS AS ((`views_after` - `views_before`)) STORED,
  `sales_before` int unsigned DEFAULT '0',
  `sales_after` int unsigned DEFAULT '0',
  `sales_increase` int GENERATED ALWAYS AS ((`sales_after` - `sales_before`)) STORED,
  `changes_applied` json DEFAULT NULL,
  `ai_suggestions` json DEFAULT NULL,
  `status` enum('pending','applied','reverted','failed') COLLATE utf8mb4_unicode_ci DEFAULT 'pending',
  `applied_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_account_id` (`account_id`),
  KEY `idx_item_id` (`item_id`),
  KEY `idx_optimization_type` (`optimization_type`),
  KEY `idx_status` (`status`),
  KEY `idx_created_at` (`created_at`),
  KEY `idx_account_created` (`account_id`,`created_at`)
) ENGINE=InnoDB AUTO_INCREMENT=34 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `seo_performance_metrics`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `seo_performance_metrics` (
  `id` int NOT NULL AUTO_INCREMENT,
  `account_id` int NOT NULL,
  `item_id` varchar(50) NOT NULL,
  `metric_date` date NOT NULL,
  `views` int DEFAULT '0',
  `visits` int DEFAULT '0',
  `unique_visitors` int unsigned DEFAULT '0',
  `sold_quantity` int DEFAULT '0',
  `revenue` decimal(12,2) DEFAULT '0.00',
  `conversion_rate` decimal(5,2) DEFAULT '0.00',
  `position_avg` decimal(5,2) DEFAULT '0.00',
  `was_optimized` tinyint(1) DEFAULT '0',
  `optimization_date` date DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `questions` int unsigned DEFAULT '0',
  `sales` int unsigned DEFAULT '0',
  `seo_score` tinyint unsigned DEFAULT NULL COMMENT 'SEO score on this date',
  `search_rank` int unsigned DEFAULT NULL COMMENT 'Average search ranking position',
  `traffic_sources` json DEFAULT NULL COMMENT 'Breakdown of traffic sources',
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_item_date` (`item_id`,`metric_date`),
  KEY `idx_account` (`account_id`),
  KEY `idx_item` (`item_id`),
  KEY `idx_date` (`metric_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `seo_score_alerts`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `seo_score_alerts` (
  `id` int NOT NULL AUTO_INCREMENT,
  `account_id` int NOT NULL,
  `item_id` varchar(50) NOT NULL,
  `alert_type` varchar(50) DEFAULT NULL,
  `message` text,
  `severity` enum('low','medium','high') DEFAULT NULL,
  `is_read` tinyint(1) DEFAULT '0',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_account_unread` (`account_id`,`is_read`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `seo_scores`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `seo_scores` (
  `id` int NOT NULL AUTO_INCREMENT,
  `item_id` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
  `account_id` int NOT NULL,
  `overall_score` int DEFAULT '0',
  `title_score` int DEFAULT '0',
  `description_score` int DEFAULT '0',
  `attributes_score` int DEFAULT '0',
  `images_score` int DEFAULT '0',
  `strategies_json` json DEFAULT NULL,
  `analyzed_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_item_id` (`item_id`),
  KEY `idx_account_id` (`account_id`),
  KEY `idx_overall_score` (`overall_score`),
  KEY `idx_analyzed_at` (`analyzed_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `seo_scores_history`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `seo_scores_history` (
  `id` int NOT NULL AUTO_INCREMENT,
  `account_id` int NOT NULL,
  `item_id` varchar(50) NOT NULL,
  `overall_score` decimal(5,2) NOT NULL,
  `breakdown_json` text,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_item_date` (`item_id`,`created_at`),
  KEY `idx_account` (`account_id`)
) ENGINE=InnoDB AUTO_INCREMENT=1794 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `seo_synonym_hierarchy`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `seo_synonym_hierarchy` (
  `id` int NOT NULL AUTO_INCREMENT,
  `category_id` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'ID da categoria ML (ex: MLB3530)',
  `level` enum('nivel_1','nivel_2','nivel_3','nivel_4') COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'Nível hierárquico',
  `word` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'Sinônimo ou termo',
  `weight` decimal(3,2) DEFAULT '1.00' COMMENT 'Peso do sinônimo (0.00-1.00)',
  `destination` enum('title','model','description','keywords') COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'Campo de destino',
  `is_active` tinyint(1) DEFAULT '1' COMMENT 'Se o sinônimo está ativo',
  `source` enum('manual','ai','ml_api','imported') COLLATE utf8mb4_unicode_ci DEFAULT 'manual' COMMENT 'Origem do dado',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_category_level_word` (`category_id`,`level`,`word`),
  KEY `idx_category` (`category_id`),
  KEY `idx_level` (`level`),
  KEY `idx_destination` (`destination`),
  KEY `idx_active` (`is_active`),
  KEY `idx_word` (`word`(50))
) ENGINE=InnoDB AUTO_INCREMENT=74 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Hierarquia de sinônimos para SEO por categoria';
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `seo_use_contexts`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `seo_use_contexts` (
  `id` int NOT NULL AUTO_INCREMENT,
  `category_id` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'ID da categoria ML',
  `context_type` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'Tipo: profissional, lazer, urbano, carga',
  `keyword` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'Palavra-chave do contexto',
  `weight` decimal(3,2) DEFAULT '1.00' COMMENT 'Peso do contexto (0.00-2.00)',
  `is_active` tinyint(1) DEFAULT '1',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_category_context_keyword` (`category_id`,`context_type`,`keyword`),
  KEY `idx_category_context` (`category_id`,`context_type`),
  KEY `idx_active` (`is_active`)
) ENGINE=InnoDB AUTO_INCREMENT=42 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Contextos de uso para SEO por categoria';
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `settings`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `settings` (
  `account_id` int NOT NULL,
  `key_name` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
  `value` text COLLATE utf8mb4_unicode_ci,
  `updated_at` datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`account_id`,`key_name`),
  CONSTRAINT `settings_ibfk_1` FOREIGN KEY (`account_id`) REFERENCES `ml_accounts` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `settlements`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `settlements` (
  `id` int NOT NULL AUTO_INCREMENT,
  `account_id` int NOT NULL,
  `file_name` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `file_path` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `uploaded_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `processed` tinyint(1) DEFAULT '0',
  `processed_at` timestamp NULL DEFAULT NULL,
  `total_amount` decimal(15,2) DEFAULT '0.00',
  `currency` varchar(3) COLLATE utf8mb4_unicode_ci DEFAULT 'BRL',
  `notes` text COLLATE utf8mb4_unicode_ci,
  PRIMARY KEY (`id`),
  KEY `idx_account` (`account_id`),
  KEY `idx_processed` (`processed`),
  KEY `idx_uploaded` (`uploaded_at`),
  CONSTRAINT `settlements_ibfk_1` FOREIGN KEY (`account_id`) REFERENCES `ml_accounts` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `shipments`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `shipments` (
  `id` int NOT NULL AUTO_INCREMENT,
  `account_id` int NOT NULL,
  `shipment_id` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
  `order_id` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `status` varchar(30) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `tracking_number` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `carrier` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `is_delayed` tinyint(1) DEFAULT '0',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `shipped_at` timestamp NULL DEFAULT NULL,
  `delivered_at` timestamp NULL DEFAULT NULL,
  `data` json DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_shipment` (`account_id`,`shipment_id`),
  KEY `idx_account` (`account_id`),
  KEY `idx_order` (`order_id`),
  KEY `idx_status` (`status`),
  KEY `idx_created` (`created_at`),
  KEY `idx_delayed` (`is_delayed`),
  CONSTRAINT `shipments_ibfk_1` FOREIGN KEY (`account_id`) REFERENCES `ml_accounts` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=1911 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `shopee_auth`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `shopee_auth` (
  `shop_id` bigint NOT NULL,
  `shop_name` varchar(255) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `access_token` text COLLATE utf8mb4_general_ci,
  `refresh_token` text COLLATE utf8mb4_general_ci,
  `token_expiry` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`shop_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `shopee_items`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `shopee_items` (
  `id` int NOT NULL AUTO_INCREMENT,
  `shopee_item_id` bigint DEFAULT NULL,
  `shop_id` bigint DEFAULT NULL,
  `name` varchar(255) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `sku` varchar(100) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `price` decimal(10,2) DEFAULT NULL,
  `stock` int DEFAULT NULL,
  `status` varchar(50) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `shopee_item_id` (`shopee_item_id`),
  KEY `idx_sku` (`sku`)
) ENGINE=InnoDB AUTO_INCREMENT=6 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `sse_connections`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `sse_connections` (
  `id` int NOT NULL AUTO_INCREMENT,
  `account_id` int NOT NULL,
  `connection_id` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `stream_type` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
  `client_ip` varchar(45) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `connected_at` datetime DEFAULT CURRENT_TIMESTAMP,
  `last_heartbeat` datetime DEFAULT NULL,
  `disconnected_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_connection` (`connection_id`),
  KEY `idx_account` (`account_id`),
  KEY `idx_active` (`disconnected_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `stock_sync_history`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `stock_sync_history` (
  `id` int NOT NULL AUTO_INCREMENT,
  `rule_id` int NOT NULL,
  `queue_id` int DEFAULT NULL,
  `source_account_id` int NOT NULL,
  `target_account_id` int NOT NULL,
  `source_item_id` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
  `target_item_id` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
  `source_quantity` int NOT NULL,
  `target_quantity_before` int DEFAULT NULL,
  `target_quantity_after` int NOT NULL,
  `sync_mode` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL,
  `trigger_type` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL,
  `status` enum('success','failed','skipped') COLLATE utf8mb4_unicode_ci NOT NULL,
  `error_message` text COLLATE utf8mb4_unicode_ci,
  `api_response` text COLLATE utf8mb4_unicode_ci COMMENT 'ML API response JSON',
  `duration_ms` int DEFAULT NULL COMMENT 'Duration in milliseconds',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_rule_id` (`rule_id`),
  KEY `idx_source_item` (`source_item_id`),
  KEY `idx_target_item` (`target_item_id`),
  KEY `idx_status` (`status`),
  KEY `idx_created_at` (`created_at`),
  KEY `idx_trigger_type` (`trigger_type`),
  CONSTRAINT `stock_sync_history_ibfk_1` FOREIGN KEY (`rule_id`) REFERENCES `stock_sync_rules` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `stock_sync_queue`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `stock_sync_queue` (
  `id` int NOT NULL AUTO_INCREMENT,
  `rule_id` int NOT NULL,
  `source_quantity` int NOT NULL,
  `target_quantity_before` int DEFAULT NULL,
  `target_quantity_calculated` int NOT NULL,
  `trigger_type` enum('webhook','full_sync','incremental','manual') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'incremental',
  `status` enum('pending','processing','completed','failed','skipped') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'pending',
  `priority` tinyint unsigned DEFAULT '5',
  `attempts` int DEFAULT '0',
  `max_attempts` int DEFAULT '3',
  `last_error` text COLLATE utf8mb4_unicode_ci,
  `next_retry_at` datetime DEFAULT NULL,
  `processed_at` datetime DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_status_priority` (`status`,`priority`,`created_at`),
  KEY `idx_rule_id` (`rule_id`),
  KEY `idx_next_retry` (`status`,`next_retry_at`),
  KEY `idx_trigger_type` (`trigger_type`),
  KEY `idx_processed` (`processed_at`),
  CONSTRAINT `stock_sync_queue_ibfk_1` FOREIGN KEY (`rule_id`) REFERENCES `stock_sync_rules` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `stock_sync_rules`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `stock_sync_rules` (
  `id` int NOT NULL AUTO_INCREMENT,
  `user_id` int NOT NULL,
  `source_account_id` int NOT NULL,
  `target_account_id` int NOT NULL,
  `source_item_id` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
  `target_item_id` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
  `sync_mode` enum('mirror','offset','percentage','custom') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'mirror',
  `offset_value` int DEFAULT '0' COMMENT 'For offset mode: target = source + offset',
  `percentage_value` decimal(5,2) DEFAULT '100.00' COMMENT 'For percentage mode: target = source * pct/100',
  `min_stock` int DEFAULT '0' COMMENT 'Minimum stock to keep on target',
  `max_stock` int DEFAULT NULL COMMENT 'Maximum stock allowed on target',
  `priority` tinyint unsigned DEFAULT '5' COMMENT '1=highest, 10=lowest',
  `is_active` tinyint(1) DEFAULT '1',
  `last_synced_at` datetime DEFAULT NULL,
  `last_source_quantity` int DEFAULT NULL,
  `last_target_quantity` int DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_source_target` (`source_item_id`,`target_item_id`),
  KEY `idx_user_id` (`user_id`),
  KEY `idx_source_account` (`source_account_id`),
  KEY `idx_target_account` (`target_account_id`),
  KEY `idx_source_item` (`source_item_id`),
  KEY `idx_target_item` (`target_item_id`),
  KEY `idx_active_priority` (`is_active`,`priority`),
  KEY `idx_last_synced` (`last_synced_at`),
  CONSTRAINT `stock_sync_rules_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `stock_sync_rules_ibfk_2` FOREIGN KEY (`source_account_id`) REFERENCES `ml_accounts` (`id`) ON DELETE CASCADE,
  CONSTRAINT `stock_sync_rules_ibfk_3` FOREIGN KEY (`target_account_id`) REFERENCES `ml_accounts` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `stock_sync_settings`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `stock_sync_settings` (
  `id` int NOT NULL AUTO_INCREMENT,
  `user_id` int NOT NULL,
  `is_enabled` tinyint(1) DEFAULT '1',
  `rate_limit_per_minute` int DEFAULT '30' COMMENT 'Max API calls per minute',
  `full_sync_interval_minutes` int DEFAULT '60' COMMENT 'How often to run full sync',
  `webhook_enabled` tinyint(1) DEFAULT '1',
  `notify_on_error` tinyint(1) DEFAULT '1',
  `notify_on_sync` tinyint(1) DEFAULT '0',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_user_id` (`user_id`),
  CONSTRAINT `stock_sync_settings_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `support_tickets`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `support_tickets` (
  `id` int NOT NULL AUTO_INCREMENT,
  `account_id` int NOT NULL,
  `ticket_id` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
  `user_id` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `type` varchar(30) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'complaint, question, return, technical',
  `priority` varchar(20) COLLATE utf8mb4_unicode_ci DEFAULT 'normal' COMMENT 'low, normal, high, urgent',
  `status` varchar(20) COLLATE utf8mb4_unicode_ci DEFAULT 'open' COMMENT 'open, in_progress, resolved, closed',
  `subject` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `description` text COLLATE utf8mb4_unicode_ci,
  `entities` json DEFAULT NULL,
  `assigned_to` int DEFAULT NULL,
  `resolved_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_ticket` (`ticket_id`),
  KEY `idx_account` (`account_id`),
  KEY `idx_user` (`user_id`),
  KEY `idx_type` (`type`),
  KEY `idx_priority` (`priority`),
  KEY `idx_status` (`status`),
  KEY `idx_created` (`created_at`),
  CONSTRAINT `support_tickets_ibfk_1` FOREIGN KEY (`account_id`) REFERENCES `ml_accounts` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `sync_logs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `sync_logs` (
  `id` int NOT NULL AUTO_INCREMENT,
  `ml_account_id` int NOT NULL,
  `sync_type` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
  `status` enum('success','error','pending') COLLATE utf8mb4_unicode_ci DEFAULT 'pending',
  `message` text COLLATE utf8mb4_unicode_ci,
  `data` json DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_account_id` (`ml_account_id`),
  KEY `idx_sync_type` (`sync_type`),
  KEY `idx_status` (`status`),
  KEY `idx_created_at` (`created_at`),
  CONSTRAINT `sync_logs_ibfk_1` FOREIGN KEY (`ml_account_id`) REFERENCES `ml_accounts` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `sync_status`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `sync_status` (
  `id` int NOT NULL AUTO_INCREMENT,
  `resource_type` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'orders, items, questions, messages',
  `account_id` int NOT NULL,
  `last_sync_at` datetime DEFAULT NULL,
  `status` enum('success','error','running') COLLATE utf8mb4_unicode_ci DEFAULT 'success',
  `last_sync_id` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'Último ID sincronizado (scroll_id, offset)',
  `items_count` int DEFAULT NULL COMMENT 'Total de itens sincronizados',
  `error_message` text COLLATE utf8mb4_unicode_ci,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_resource_account` (`resource_type`,`account_id`),
  KEY `idx_account` (`account_id`),
  KEY `idx_status` (`status`),
  KEY `idx_last_sync` (`last_sync_at`),
  CONSTRAINT `fk_sync_status_account` FOREIGN KEY (`account_id`) REFERENCES `ml_accounts` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=14447 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Controle de sincronizações automáticas por recurso/conta';
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `system_alerts`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `system_alerts` (
  `id` int NOT NULL AUTO_INCREMENT,
  `alert_type` enum('critical','warning','info') COLLATE utf8mb4_general_ci NOT NULL,
  `title` varchar(200) COLLATE utf8mb4_general_ci NOT NULL,
  `message` text COLLATE utf8mb4_general_ci NOT NULL,
  `metric_name` varchar(100) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `threshold_value` decimal(10,4) DEFAULT NULL,
  `current_value` decimal(10,4) DEFAULT NULL,
  `status` enum('active','acknowledged','resolved') COLLATE utf8mb4_general_ci DEFAULT 'active',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `acknowledged_at` timestamp NULL DEFAULT NULL,
  `resolved_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_status_type` (`status`,`alert_type`),
  KEY `idx_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `system_config`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `system_config` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `config_key` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `config_value` json DEFAULT NULL,
  `description` text COLLATE utf8mb4_unicode_ci,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `config_key` (`config_key`),
  KEY `idx_key` (`config_key`)
) ENGINE=InnoDB AUTO_INCREMENT=76 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `system_logs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `system_logs` (
  `id` bigint NOT NULL AUTO_INCREMENT,
  `session_id` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
  `level` enum('DEBUG','INFO','WARNING','ERROR','CRITICAL') COLLATE utf8mb4_unicode_ci NOT NULL,
  `category` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
  `message` text COLLATE utf8mb4_unicode_ci NOT NULL,
  `context` json DEFAULT NULL,
  `user_id` int DEFAULT NULL,
  `ip_address` varchar(45) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `user_agent` text COLLATE utf8mb4_unicode_ci,
  `request_uri` text COLLATE utf8mb4_unicode_ci,
  `execution_time` decimal(10,4) DEFAULT NULL,
  `memory_usage` int DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_level` (`level`),
  KEY `idx_category` (`category`),
  KEY `idx_session` (`session_id`),
  KEY `idx_created` (`created_at`),
  KEY `idx_level_category` (`level`,`category`)
) ENGINE=InnoDB AUTO_INCREMENT=112243 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `system_metrics`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `system_metrics` (
  `id` int NOT NULL AUTO_INCREMENT,
  `metric_type` varchar(50) COLLATE utf8mb4_general_ci NOT NULL,
  `metric_name` varchar(100) COLLATE utf8mb4_general_ci NOT NULL,
  `value` decimal(10,4) NOT NULL,
  `unit` varchar(20) COLLATE utf8mb4_general_ci NOT NULL,
  `timestamp` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `server_id` varchar(50) COLLATE utf8mb4_general_ci DEFAULT 'main',
  PRIMARY KEY (`id`),
  KEY `idx_type_time` (`metric_type`,`timestamp`),
  KEY `idx_name_time` (`metric_name`,`timestamp`)
) ENGINE=InnoDB AUTO_INCREMENT=180 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `system_monitoring`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `system_monitoring` (
  `id` int NOT NULL AUTO_INCREMENT,
  `metric_name` varchar(100) NOT NULL,
  `metric_value` decimal(15,4) DEFAULT NULL,
  `metric_unit` varchar(20) DEFAULT NULL,
  `status` enum('ok','warning','critical') DEFAULT 'ok',
  `metadata` json DEFAULT NULL,
  `recorded_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_metric` (`metric_name`),
  KEY `idx_recorded` (`recorded_at`)
) ENGINE=InnoDB AUTO_INCREMENT=8497 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `task_states`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `task_states` (
  `id` int NOT NULL AUTO_INCREMENT,
  `task_db_id` int NOT NULL,
  `before_state` json DEFAULT NULL,
  `after_state` json DEFAULT NULL,
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_task` (`task_db_id`),
  CONSTRAINT `task_states_ibfk_1` FOREIGN KEY (`task_db_id`) REFERENCES `workflow_tasks` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `tech_sheet_alert_recipients`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `tech_sheet_alert_recipients` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `rule_id` int unsigned NOT NULL,
  `email` varchar(200) COLLATE utf8mb4_unicode_ci NOT NULL,
  `status` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'active',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_rule_email` (`rule_id`,`email`),
  KEY `idx_rule` (`rule_id`),
  KEY `idx_rule_id` (`rule_id`),
  CONSTRAINT `fk_alert_recipients_rule` FOREIGN KEY (`rule_id`) REFERENCES `tech_sheet_alert_rules` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Recipients for alert rules';
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `tech_sheet_alert_rules`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `tech_sheet_alert_rules` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `account_id` int unsigned NOT NULL,
  `name` varchar(200) COLLATE utf8mb4_unicode_ci NOT NULL,
  `type` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'completeness, suggestions, performance, etc',
  `conditions` json NOT NULL COMMENT 'Array of conditions: [{"field": "completeness", "operator": "<", "value": 50}]',
  `channels` json NOT NULL COMMENT 'Array of channels: ["email", "webhook", "slack"]',
  `cooldown_minutes` int unsigned NOT NULL DEFAULT '60',
  `status` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'active',
  `trigger_count` int unsigned NOT NULL DEFAULT '0',
  `last_triggered_at` datetime DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_account_type` (`account_id`,`type`),
  KEY `idx_status` (`status`)
) ENGINE=InnoDB AUTO_INCREMENT=12 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Custom alert rules for Tech Sheet';
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `tech_sheet_alerts`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `tech_sheet_alerts` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `account_id` int unsigned NOT NULL,
  `rule_id` int unsigned NOT NULL,
  `data` json NOT NULL COMMENT 'Alert data that triggered the rule',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_account_created` (`account_id`,`created_at`),
  KEY `idx_rule` (`rule_id`),
  KEY `idx_rule_id` (`rule_id`),
  CONSTRAINT `fk_alerts_rule` FOREIGN KEY (`rule_id`) REFERENCES `tech_sheet_alert_rules` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='History of triggered alerts';
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `tech_sheet_execution_log`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `tech_sheet_execution_log` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `account_id` int unsigned NOT NULL,
  `item_id` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'ML item ID (null for batch operations)',
  `action` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'generate, apply, auto_optimize, batch, etc',
  `result` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'success, failed, partial',
  `details` json DEFAULT NULL COMMENT 'Execution details',
  `error_message` text COLLATE utf8mb4_unicode_ci,
  `duration_ms` int unsigned DEFAULT NULL COMMENT 'Execution time in milliseconds',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_account_action` (`account_id`,`action`),
  KEY `idx_item` (`item_id`),
  KEY `idx_result` (`result`),
  KEY `idx_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Execution log for Tech Sheet operations';
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `tech_sheet_item_summary`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `tech_sheet_item_summary` (
  `id` int NOT NULL AUTO_INCREMENT,
  `account_id` int NOT NULL,
  `item_id` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
  `category_id` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `total_available` int DEFAULT '0',
  `filled` int DEFAULT '0',
  `missing` int DEFAULT '0',
  `completeness_percent` decimal(5,1) DEFAULT '0.0',
  `missing_required` int DEFAULT '0',
  `missing_filter` int DEFAULT '0',
  `missing_hidden` int DEFAULT '0',
  `missing_recommended` int DEFAULT '0',
  `last_analyzed_at` datetime DEFAULT NULL,
  `meta` json DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_account_item` (`account_id`,`item_id`),
  KEY `idx_account_completeness` (`account_id`,`completeness_percent`),
  KEY `idx_account_updated` (`account_id`,`updated_at`),
  KEY `idx_account_category` (`account_id`,`category_id`),
  KEY `idx_account_id` (`account_id`),
  CONSTRAINT `fk_tech_sheet_summary_account` FOREIGN KEY (`account_id`) REFERENCES `ml_accounts` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=2099 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Resumo de completude de ficha técnica por item (cache para listagem)';
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `tech_sheet_scheduled_jobs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `tech_sheet_scheduled_jobs` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `account_id` int unsigned NOT NULL,
  `job_type` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'auto_optimizer, email_report, batch_analysis, cleanup',
  `schedule_cron` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'Cron expression',
  `config` json DEFAULT NULL COMMENT 'Job configuration',
  `status` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'active' COMMENT 'active, paused, failed',
  `last_run_at` datetime DEFAULT NULL,
  `next_run_at` datetime DEFAULT NULL,
  `last_result` json DEFAULT NULL COMMENT 'Last execution result',
  `run_count` int unsigned NOT NULL DEFAULT '0',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_account_status` (`account_id`,`status`),
  KEY `idx_next_run` (`next_run_at`),
  KEY `idx_job_type` (`job_type`)
) ENGINE=InnoDB AUTO_INCREMENT=12 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Scheduled jobs for Tech Sheet automation';
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `tech_sheet_suggestions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `tech_sheet_suggestions` (
  `id` int NOT NULL AUTO_INCREMENT,
  `account_id` int NOT NULL,
  `item_id` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
  `category_id` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `attribute_id` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `attribute_name` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `suggested_value` text COLLATE utf8mb4_unicode_ci NOT NULL,
  `source` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT 'inference',
  `confidence` tinyint unsigned DEFAULT NULL,
  `status` enum('pending','approved','rejected','applied') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'pending',
  `decided_by_user_id` int DEFAULT NULL,
  `decided_at` datetime DEFAULT NULL,
  `applied_at` datetime DEFAULT NULL,
  `meta` json DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_account_item_attr` (`account_id`,`item_id`,`attribute_id`),
  KEY `idx_account_item_status` (`account_id`,`item_id`,`status`),
  KEY `idx_account_status` (`account_id`,`status`),
  KEY `idx_account_created` (`account_id`,`created_at`),
  KEY `idx_auto_optimize` (`account_id`,`status`,`confidence`,`source`),
  KEY `idx_account_id` (`account_id`),
  KEY `idx_decided_by_user_id` (`decided_by_user_id`),
  CONSTRAINT `fk_tech_sheet_suggestions_account` FOREIGN KEY (`account_id`) REFERENCES `ml_accounts` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_tech_sheet_suggestions_user` FOREIGN KEY (`decided_by_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=293 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Sugestões para preenchimento de atributos com fluxo de aprovação';
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `tech_sheet_webhooks`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `tech_sheet_webhooks` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `account_id` int unsigned NOT NULL,
  `type` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'slack, telegram, http',
  `url` varchar(500) COLLATE utf8mb4_unicode_ci NOT NULL,
  `config` json DEFAULT NULL COMMENT 'Bot tokens, channels, headers, etc',
  `events` json NOT NULL COMMENT 'Array of events to listen: ["*"] or ["suggestions.generated", "alert.critical"]',
  `status` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'active' COMMENT 'active, paused, failed',
  `last_triggered_at` datetime DEFAULT NULL,
  `last_error` text COLLATE utf8mb4_unicode_ci,
  `last_error_at` datetime DEFAULT NULL,
  `success_count` int unsigned NOT NULL DEFAULT '0',
  `failure_count` int unsigned NOT NULL DEFAULT '0',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_account_status` (`account_id`,`status`),
  KEY `idx_type` (`type`)
) ENGINE=InnoDB AUTO_INCREMENT=11 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Webhooks configuration for Tech Sheet notifications';
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `token_refresh_audit`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `token_refresh_audit` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `account_id` int NOT NULL,
  `action` enum('refresh_attempt','refresh_success','refresh_failed','authorization_granted','token_expired','lock_acquired','lock_timeout','refresh_disconnected') COLLATE utf8mb4_unicode_ci NOT NULL,
  `details` json DEFAULT NULL COMMENT 'Detalhes adicionais em formato JSON',
  `http_code` int DEFAULT NULL COMMENT 'Código HTTP da resposta da API ML',
  `error_message` text COLLATE utf8mb4_unicode_ci COMMENT 'Mensagem de erro se aplicável',
  `expires_at_before` datetime DEFAULT NULL COMMENT 'Data de expiração antes do refresh',
  `expires_at_after` datetime DEFAULT NULL COMMENT 'Data de expiração após o refresh',
  `execution_time_ms` int DEFAULT NULL COMMENT 'Tempo de execução em milissegundos',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_account_id` (`account_id`),
  KEY `idx_action` (`action`),
  KEY `idx_created_at` (`created_at`),
  KEY `idx_account_action` (`account_id`,`action`),
  KEY `idx_account_created` (`account_id`,`created_at`),
  KEY `idx_dashboard_queries` (`action`,`created_at`,`account_id`),
  KEY `idx_failure_analysis` (`account_id`,`action`,`created_at`),
  CONSTRAINT `fk_tra_account_id` FOREIGN KEY (`account_id`) REFERENCES `ml_accounts` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=7835 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Auditoria de renovações de tokens do Mercado Livre';
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `training_history`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `training_history` (
  `id` int NOT NULL AUTO_INCREMENT,
  `account_id` varchar(100) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `network_id` int DEFAULT NULL,
  `training_step` int NOT NULL,
  `epoch` int DEFAULT NULL,
  `loss_value` decimal(10,8) NOT NULL,
  `accuracy` decimal(5,4) DEFAULT NULL,
  `reward_avg` decimal(10,6) DEFAULT NULL,
  `exploration_rate` decimal(5,4) DEFAULT NULL,
  `learning_rate` decimal(8,6) DEFAULT NULL,
  `batch_size` int DEFAULT NULL,
  `training_time_ms` int DEFAULT NULL,
  `validation_loss` decimal(10,8) DEFAULT NULL,
  `gradient_norm` decimal(10,6) DEFAULT NULL,
  `metrics_json` json DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_account_step` (`account_id`,`training_step`),
  KEY `idx_network_step` (`network_id`,`training_step`),
  KEY `idx_created_at` (`created_at`),
  KEY `idx_training_history_composite` (`account_id`,`network_id`,`training_step` DESC),
  KEY `idx_network_id` (`network_id`),
  CONSTRAINT `training_history_ibfk_1` FOREIGN KEY (`network_id`) REFERENCES `neural_networks` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci ROW_FORMAT=COMPRESSED KEY_BLOCK_SIZE=8;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `transfer_learning_experiences`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `transfer_learning_experiences` (
  `id` int NOT NULL AUTO_INCREMENT,
  `source_account_id` varchar(100) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `target_account_id` varchar(100) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `source_category` varchar(50) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `target_category` varchar(50) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `source_knowledge` json DEFAULT NULL,
  `target_characteristics` json DEFAULT NULL,
  `transferable_patterns` json DEFAULT NULL,
  `adaptation_quality` decimal(5,4) DEFAULT NULL,
  `knowledge_gain` decimal(5,4) DEFAULT NULL,
  `transfer_effectiveness` decimal(5,4) DEFAULT NULL,
  `fine_tuning_episodes` int DEFAULT NULL,
  `final_performance` decimal(5,4) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_source_target` (`source_account_id`,`target_account_id`),
  KEY `idx_categories` (`source_category`,`target_category`),
  KEY `idx_effectiveness` (`transfer_effectiveness` DESC)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `user_api_keys`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `user_api_keys` (
  `id` int NOT NULL AUTO_INCREMENT,
  `account_id` int NOT NULL,
  `client_id` varchar(64) NOT NULL,
  `client_secret_hash` varchar(255) NOT NULL,
  `name` varchar(100) NOT NULL COMMENT 'Key identifier/name',
  `permissions` json DEFAULT NULL COMMENT 'JSON array of scopes',
  `last_used_at` datetime DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `status` enum('active','revoked') DEFAULT 'active',
  PRIMARY KEY (`id`),
  UNIQUE KEY `client_id` (`client_id`),
  KEY `account_id` (`account_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `user_products`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `user_products` (
  `id` int NOT NULL AUTO_INCREMENT,
  `account_id` int NOT NULL,
  `product_id` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
  `name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `category_id` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `status` varchar(20) COLLATE utf8mb4_unicode_ci DEFAULT 'active',
  `data` json DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_product` (`account_id`,`product_id`),
  KEY `idx_account` (`account_id`),
  KEY `idx_status` (`status`),
  KEY `idx_category` (`category_id`),
  KEY `idx_created` (`created_at`),
  CONSTRAINT `user_products_ibfk_1` FOREIGN KEY (`account_id`) REFERENCES `ml_accounts` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `user_sessions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `user_sessions` (
  `id` varchar(128) COLLATE utf8mb4_unicode_ci NOT NULL,
  `user_id` int NOT NULL,
  `ip_address` varchar(45) COLLATE utf8mb4_unicode_ci NOT NULL,
  `user_agent` text COLLATE utf8mb4_unicode_ci,
  `payload` text COLLATE utf8mb4_unicode_ci NOT NULL,
  `last_activity` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_user_id` (`user_id`),
  KEY `idx_last_activity` (`last_activity`),
  CONSTRAINT `user_sessions_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `users`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `users` (
  `id` int NOT NULL AUTO_INCREMENT,
  `name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `email` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `role` enum('admin','manager','support','user','viewer') COLLATE utf8mb4_unicode_ci DEFAULT 'admin',
  `phone` varchar(20) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `password` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `status` enum('active','inactive','suspended') COLLATE utf8mb4_unicode_ci DEFAULT 'active',
  `remember_token` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `email_verified_at` timestamp NULL DEFAULT NULL,
  `two_factor_secret` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `two_factor_recovery_codes` text COLLATE utf8mb4_unicode_ci,
  `two_factor_enabled` tinyint(1) DEFAULT '0',
  `dashboard_preferences` json DEFAULT NULL,
  `theme` varchar(20) COLLATE utf8mb4_unicode_ci DEFAULT 'light',
  `verification_token` varchar(64) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `last_login` timestamp NULL DEFAULT NULL,
  `notification_preferences` json DEFAULT NULL,
  `active_ml_account_id` int DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `email` (`email`),
  KEY `idx_email` (`email`),
  KEY `idx_active_ml_account` (`active_ml_account_id`),
  KEY `idx_status` (`status`),
  KEY `idx_email_users` (`email`),
  KEY `idx_created_at_users` (`created_at`)
) ENGINE=InnoDB AUTO_INCREMENT=100223 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `v_latest_governance_diagnostic`;
/*!50001 DROP VIEW IF EXISTS `v_latest_governance_diagnostic`*/;
SET @saved_cs_client     = @@character_set_client;
/*!50503 SET character_set_client = utf8mb4 */;
/*!50001 CREATE VIEW `v_latest_governance_diagnostic` AS SELECT 
 1 AS `id`,
 1 AS `account_id`,
 1 AS `account_status`,
 1 AS `total_items`,
 1 AS `healthy_items`,
 1 AS `problem_items`,
 1 AS `critical_actions`,
 1 AS `top_causes`,
 1 AS `executive_summary`,
 1 AS `full_result`,
 1 AS `created_at`*/;
SET character_set_client = @saved_cs_client;
DROP TABLE IF EXISTS `v_performance_summary`;
/*!50001 DROP VIEW IF EXISTS `v_performance_summary`*/;
SET @saved_cs_client     = @@character_set_client;
/*!50503 SET character_set_client = utf8mb4 */;
/*!50001 CREATE VIEW `v_performance_summary` AS SELECT 
 1 AS `metric_type`,
 1 AS `total_count`,
 1 AS `avg_ms`,
 1 AS `max_ms`,
 1 AS `slow_count`,
 1 AS `date`*/;
SET character_set_client = @saved_cs_client;
DROP TABLE IF EXISTS `v_seo_active_keywords`;
/*!50001 DROP VIEW IF EXISTS `v_seo_active_keywords`*/;
SET @saved_cs_client     = @@character_set_client;
/*!50503 SET character_set_client = utf8mb4 */;
/*!50001 CREATE VIEW `v_seo_active_keywords` AS SELECT 
 1 AS `category_id`,
 1 AS `keyword`,
 1 AS `type`,
 1 AS `weight`,
 1 AS `source`,
 1 AS `expires_at`*/;
SET character_set_client = @saved_cs_client;
DROP TABLE IF EXISTS `v_seo_synonym_summary`;
/*!50001 DROP VIEW IF EXISTS `v_seo_synonym_summary`*/;
SET @saved_cs_client     = @@character_set_client;
/*!50503 SET character_set_client = utf8mb4 */;
/*!50001 CREATE VIEW `v_seo_synonym_summary` AS SELECT 
 1 AS `category_id`,
 1 AS `level`,
 1 AS `total_synonyms`,
 1 AS `avg_weight`,
 1 AS `destination`*/;
SET character_set_client = @saved_cs_client;
DROP TABLE IF EXISTS `vw_ean_balance_summary`;
/*!50001 DROP VIEW IF EXISTS `vw_ean_balance_summary`*/;
SET @saved_cs_client     = @@character_set_client;
/*!50503 SET character_set_client = utf8mb4 */;
/*!50001 CREATE VIEW `vw_ean_balance_summary` AS SELECT 
 1 AS `account_id`,
 1 AS `nickname`,
 1 AS `total_purchased`,
 1 AS `total_used`,
 1 AS `available`,
 1 AS `last_purchase_at`*/;
SET character_set_client = @saved_cs_client;
DROP TABLE IF EXISTS `vw_ean_inventory_summary`;
/*!50001 DROP VIEW IF EXISTS `vw_ean_inventory_summary`*/;
SET @saved_cs_client     = @@character_set_client;
/*!50503 SET character_set_client = utf8mb4 */;
/*!50001 CREATE VIEW `vw_ean_inventory_summary` AS SELECT 
 1 AS `status`,
 1 AS `quantity`,
 1 AS `purchase_batch`*/;
SET character_set_client = @saved_cs_client;
DROP TABLE IF EXISTS `vw_ean_sales_summary`;
/*!50001 DROP VIEW IF EXISTS `vw_ean_sales_summary`*/;
SET @saved_cs_client     = @@character_set_client;
/*!50503 SET character_set_client = utf8mb4 */;
/*!50001 CREATE VIEW `vw_ean_sales_summary` AS SELECT 
 1 AS `sale_date`,
 1 AS `total_orders`,
 1 AS `total_eans`,
 1 AS `total_revenue`,
 1 AS `paid_revenue`*/;
SET character_set_client = @saved_cs_client;
DROP TABLE IF EXISTS `webhook_event_inbox`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `webhook_event_inbox` (
  `id` bigint NOT NULL AUTO_INCREMENT,
  `provider` varchar(60) COLLATE utf8mb4_unicode_ci NOT NULL,
  `event_key` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `request_id` varchar(64) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `delivery_id` varchar(128) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `signature_ts` bigint DEFAULT NULL,
  `signature_nonce` varchar(128) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `job_id` int DEFAULT NULL,
  `payload_hash` char(64) COLLATE utf8mb4_unicode_ci NOT NULL,
  `payload_json` longtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `metadata_json` json DEFAULT NULL,
  `status` enum('received','queued','processed','failed') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'received',
  `error_message` varchar(1000) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `result_json` json DEFAULT NULL,
  `received_at` datetime NOT NULL,
  `processed_at` datetime DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_webhook_event_provider_key` (`provider`,`event_key`),
  KEY `idx_webhook_event_provider_status` (`provider`,`status`),
  KEY `idx_webhook_event_received_at` (`received_at`),
  KEY `idx_webhook_event_request_id` (`provider`,`request_id`),
  KEY `idx_webhook_event_job_id` (`job_id`),
  KEY `idx_webhook_event_delivery_id` (`provider`,`delivery_id`),
  KEY `idx_webhook_event_signature_ts` (`provider`,`signature_ts`),
  KEY `idx_webhook_event_signature_nonce` (`provider`,`signature_nonce`)
) ENGINE=InnoDB AUTO_INCREMENT=46439 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `webhook_events`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `webhook_events` (
  `id` int NOT NULL AUTO_INCREMENT,
  `topic` varchar(50) DEFAULT NULL,
  `resource` varchar(255) DEFAULT NULL,
  `user_id` bigint DEFAULT NULL,
  `application_id` bigint DEFAULT NULL,
  `payload` text,
  `status` varchar(20) DEFAULT 'pending',
  `created_at` datetime DEFAULT NULL,
  `processed_at` datetime DEFAULT NULL,
  `attempts` int DEFAULT '0',
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=27429 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `webhook_logs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `webhook_logs` (
  `id` int NOT NULL AUTO_INCREMENT,
  `topic` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `resource` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `user_id` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `payload` json DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_topic` (`topic`),
  KEY `idx_user_id` (`user_id`),
  KEY `idx_created_at` (`created_at`)
) ENGINE=InnoDB AUTO_INCREMENT=258 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `webhook_receipts`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `webhook_receipts` (
  `id` bigint NOT NULL AUTO_INCREMENT,
  `event_hash` char(64) COLLATE utf8mb4_unicode_ci NOT NULL,
  `topic` varchar(120) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `resource` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `user_id` bigint DEFAULT NULL,
  `sent_at` varchar(80) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_webhook_receipts_event_hash` (`event_hash`),
  KEY `idx_webhook_receipts_created_at` (`created_at`)
) ENGINE=InnoDB AUTO_INCREMENT=40 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `webhooks`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `webhooks` (
  `id` int NOT NULL AUTO_INCREMENT,
  `event_type` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `url` text COLLATE utf8mb4_unicode_ci NOT NULL,
  `config` json DEFAULT NULL,
  `active` tinyint(1) DEFAULT '1',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_event` (`event_type`),
  KEY `idx_active` (`active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `whatsapp_logs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `whatsapp_logs` (
  `id` int NOT NULL AUTO_INCREMENT,
  `user_id` int NOT NULL,
  `message_type` enum('sent','received','notification') DEFAULT 'sent',
  `phone_to` varchar(20) DEFAULT NULL,
  `message` text,
  `status` enum('pending','sent','delivered','read','failed') DEFAULT 'pending',
  `error_message` text,
  `metadata` json DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_user` (`user_id`),
  KEY `idx_created` (`created_at`),
  KEY `idx_user_id` (`user_id`),
  CONSTRAINT `whatsapp_logs_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `whatsapp_settings`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `whatsapp_settings` (
  `id` int NOT NULL AUTO_INCREMENT,
  `user_id` int NOT NULL,
  `phone_number` varchar(20) DEFAULT NULL,
  `api_key` varchar(255) DEFAULT NULL,
  `instance_id` varchar(100) DEFAULT NULL,
  `webhook_url` varchar(500) DEFAULT NULL,
  `status` enum('active','inactive','pending') DEFAULT 'inactive',
  `settings` json DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_user` (`user_id`),
  CONSTRAINT `whatsapp_settings_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `worker_execution_logs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `worker_execution_logs` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `worker_name` varchar(120) COLLATE utf8mb4_unicode_ci NOT NULL,
  `stats` json DEFAULT NULL,
  `executed_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_worker_execution_logs_name` (`worker_name`),
  KEY `idx_worker_execution_logs_executed_at` (`executed_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `workflow_tasks`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `workflow_tasks` (
  `id` int NOT NULL AUTO_INCREMENT,
  `workflow_id` int NOT NULL,
  `task_id` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `task_type` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
  `task_data` json DEFAULT NULL,
  `dependencies` json DEFAULT NULL,
  `result_json` json DEFAULT NULL,
  `status` enum('pending','running','completed','failed','rolled_back') COLLATE utf8mb4_unicode_ci DEFAULT 'pending',
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  `completed_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_workflow` (`workflow_id`),
  KEY `idx_status` (`status`),
  KEY `idx_workflow_status` (`workflow_id`,`status`),
  CONSTRAINT `workflow_tasks_ibfk_1` FOREIGN KEY (`workflow_id`) REFERENCES `automation_workflows` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `xray_item_scores`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `xray_item_scores` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `report_id` int unsigned NOT NULL,
  `item_id` varchar(30) NOT NULL,
  `title` varchar(255) NOT NULL,
  `category_id` varchar(30) DEFAULT NULL,
  `classification` varchar(30) DEFAULT NULL COMMENT 'ANCHOR|SAUDAVEL|EM_RISCO|FRACO|MORTO|TOXICO|POLUIDOR|SEM_ESTOQUE',
  `score_overall` tinyint unsigned NOT NULL DEFAULT '0',
  `score_seo` tinyint unsigned NOT NULL DEFAULT '0',
  `score_semantic` tinyint unsigned NOT NULL DEFAULT '0',
  `score_longtail` tinyint unsigned NOT NULL DEFAULT '0',
  `missing_keywords_json` text,
  `gap_keywords_json` text,
  `actions_json` text,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_report_id` (`report_id`),
  KEY `idx_item_id` (`item_id`),
  KEY `idx_classification` (`classification`),
  CONSTRAINT `fk_xray_item_report` FOREIGN KEY (`report_id`) REFERENCES `account_xray_reports` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=171 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci COMMENT='Raio X — Per-item SEO scores';
/*!40101 SET character_set_client = @saved_cs_client */;
/*!50001 DROP VIEW IF EXISTS `autopilot_summary`*/;
/*!50001 SET @saved_cs_client          = @@character_set_client */;
/*!50001 SET @saved_cs_results         = @@character_set_results */;
/*!50001 SET @saved_col_connection     = @@collation_connection */;
/*!50001 SET character_set_client      = utf8mb4 */;
/*!50001 SET character_set_results     = utf8mb4 */;
/*!50001 SET collation_connection      = utf8mb4_0900_ai_ci */;
/*!50001 CREATE ALGORITHM=UNDEFINED */
/*!50013 SQL SECURITY DEFINER */
/*!50001 VIEW `autopilot_summary` AS select `a`.`account_id` AS `account_id`,count(distinct `ae`.`execution_id`) AS `total_executions`,count(distinct `ac`.`cycle_id`) AS `total_cycles`,(avg(`ae`.`success_count`) / avg(`ae`.`total_count`)) AS `avg_success_rate`,sum(`ae`.`total_count`) AS `total_optimizations`,max(`ae`.`created_at`) AS `last_execution`,`a`.`status` AS `current_status` from ((`autopilot_status` `a` left join `autopilot_execution_sessions` `ae` on((`a`.`account_id` = `ae`.`account_id`))) left join `autopilot_cycles` `ac` on((`a`.`account_id` = `ac`.`account_id`))) group by `a`.`account_id`,`a`.`status` */;
/*!50001 SET character_set_client      = @saved_cs_client */;
/*!50001 SET character_set_results     = @saved_cs_results */;
/*!50001 SET collation_connection      = @saved_col_connection */;
/*!50001 DROP VIEW IF EXISTS `catalog_clone_job_stats`*/;
/*!50001 SET @saved_cs_client          = @@character_set_client */;
/*!50001 SET @saved_cs_results         = @@character_set_results */;
/*!50001 SET @saved_col_connection     = @@collation_connection */;
/*!50001 SET character_set_client      = utf8mb4 */;
/*!50001 SET character_set_results     = utf8mb4 */;
/*!50001 SET collation_connection      = utf8mb4_0900_ai_ci */;
/*!50001 CREATE ALGORITHM=UNDEFINED */
/*!50013 SQL SECURITY DEFINER */
/*!50001 VIEW `catalog_clone_job_stats` AS select `ccj`.`id` AS `id`,`ccj`.`job_id` AS `job_id`,`ccj`.`target_account_id` AS `target_account_id`,`ma`.`nickname` AS `target_account_name`,`ccj`.`source_type` AS `source_type`,`ccj`.`source_seller_id` AS `source_seller_id`,`ccj`.`status` AS `status`,`ccj`.`total_items` AS `total_items`,`ccj`.`successful_items` AS `successful_items`,`ccj`.`failed_items` AS `failed_items`,`ccj`.`skipped_items` AS `skipped_items`,round(((`ccj`.`successful_items` / nullif(`ccj`.`total_items`,0)) * 100),1) AS `success_rate`,`ccj`.`created_at` AS `created_at`,`ccj`.`completed_at` AS `completed_at`,timestampdiff(SECOND,`ccj`.`started_at`,`ccj`.`completed_at`) AS `duration_seconds` from (`catalog_clone_jobs` `ccj` left join `ml_accounts` `ma` on((`ma`.`id` = `ccj`.`target_account_id`))) */;
/*!50001 SET character_set_client      = @saved_cs_client */;
/*!50001 SET character_set_results     = @saved_cs_results */;
/*!50001 SET collation_connection      = @saved_col_connection */;
/*!50001 DROP VIEW IF EXISTS `competitor_prices_cache`*/;
/*!50001 SET @saved_cs_client          = @@character_set_client */;
/*!50001 SET @saved_cs_results         = @@character_set_results */;
/*!50001 SET @saved_col_connection     = @@collation_connection */;
/*!50001 SET character_set_client      = utf8mb4 */;
/*!50001 SET character_set_results     = utf8mb4 */;
/*!50001 SET collation_connection      = utf8mb4_0900_ai_ci */;
/*!50001 CREATE ALGORITHM=UNDEFINED */
/*!50013 SQL SECURITY DEFINER */
/*!50001 VIEW `competitor_prices_cache` AS select `competitor_pricing_cache`.`id` AS `id`,`competitor_pricing_cache`.`account_id` AS `account_id`,`competitor_pricing_cache`.`item_id` AS `item_id`,`competitor_pricing_cache`.`category_id` AS `category_id`,`competitor_pricing_cache`.`preco_minimo` AS `preco_minimo`,`competitor_pricing_cache`.`preco_maximo` AS `preco_maximo`,`competitor_pricing_cache`.`preco_medio` AS `preco_medio`,`competitor_pricing_cache`.`preco_mediano` AS `preco_mediano`,`competitor_pricing_cache`.`qtd_concorrentes` AS `qtd_concorrentes`,`competitor_pricing_cache`.`top_concorrentes` AS `top_concorrentes`,`competitor_pricing_cache`.`nossa_posicao_preco` AS `nossa_posicao_preco`,`competitor_pricing_cache`.`percentil_preco` AS `percentil_preco`,`competitor_pricing_cache`.`tendencia_7d` AS `tendencia_7d`,`competitor_pricing_cache`.`tendencia_30d` AS `tendencia_30d`,`competitor_pricing_cache`.`atualizado_em` AS `atualizado_em`,`competitor_pricing_cache`.`expira_em` AS `expira_em` from `competitor_pricing_cache` */;
/*!50001 SET character_set_client      = @saved_cs_client */;
/*!50001 SET character_set_results     = @saved_cs_results */;
/*!50001 SET collation_connection      = @saved_col_connection */;
/*!50001 DROP VIEW IF EXISTS `learning_performance`*/;
/*!50001 SET @saved_cs_client          = @@character_set_client */;
/*!50001 SET @saved_cs_results         = @@character_set_results */;
/*!50001 SET @saved_col_connection     = @@collation_connection */;
/*!50001 SET character_set_client      = utf8mb4 */;
/*!50001 SET character_set_results     = utf8mb4 */;
/*!50001 SET collation_connection      = utf8mb4_0900_ai_ci */;
/*!50001 CREATE ALGORITHM=UNDEFINED */
/*!50013 SQL SECURITY DEFINER */
/*!50001 VIEW `learning_performance` AS select `li`.`account_id` AS `account_id`,count(`li`.`id`) AS `total_learning_sessions`,avg(json_extract(`li`.`insights_data`,'$.successful_patterns_count')) AS `avg_patterns_found`,avg(json_extract(`li`.`insights_data`,'$.failure_insights_count')) AS `avg_failures_analyzed`,max(`li`.`created_at`) AS `last_learning_session` from `learning_insights` `li` group by `li`.`account_id` */;
/*!50001 SET character_set_client      = @saved_cs_client */;
/*!50001 SET character_set_results     = @saved_cs_results */;
/*!50001 SET collation_connection      = @saved_col_connection */;
/*!50001 DROP VIEW IF EXISTS `learning_summary`*/;
/*!50001 SET @saved_cs_client          = @@character_set_client */;
/*!50001 SET @saved_cs_results         = @@character_set_results */;
/*!50001 SET @saved_col_connection     = @@collation_connection */;
/*!50001 SET character_set_client      = utf8mb4 */;
/*!50001 SET character_set_results     = utf8mb4 */;
/*!50001 SET collation_connection      = utf8mb4_0900_ai_ci */;
/*!50001 CREATE ALGORITHM=UNDEFINED */
/*!50013 SQL SECURITY DEFINER */
/*!50001 VIEW `learning_summary` AS select `a`.`account_id` AS `account_id`,count(distinct `le`.`id`) AS `total_experiences`,avg(`le`.`reward`) AS `avg_reward`,max(`le`.`created_at`) AS `last_experience`,count(distinct `le`.`state_hash`) AS `unique_states`,count(distinct `le`.`action`) AS `unique_actions`,(select count(0) from `q_table` where (`q_table`.`account_id` = `a`.`account_id`)) AS `q_table_entries`,(select avg(`q_table`.`q_value`) from `q_table` where (`q_table`.`account_id` = `a`.`account_id`)) AS `avg_q_value` from ((select distinct `learning_experiences`.`account_id` AS `account_id` from `learning_experiences`) `a` left join `learning_experiences` `le` on((`le`.`account_id` = `a`.`account_id`))) group by `a`.`account_id` */;
/*!50001 SET character_set_client      = @saved_cs_client */;
/*!50001 SET character_set_results     = @saved_cs_results */;
/*!50001 SET collation_connection      = @saved_col_connection */;
/*!50001 DROP VIEW IF EXISTS `ml_items`*/;
/*!50001 SET @saved_cs_client          = @@character_set_client */;
/*!50001 SET @saved_cs_results         = @@character_set_results */;
/*!50001 SET @saved_col_connection     = @@collation_connection */;
/*!50001 SET character_set_client      = utf8mb4 */;
/*!50001 SET character_set_results     = utf8mb4 */;
/*!50001 SET collation_connection      = utf8mb4_0900_ai_ci */;
/*!50001 CREATE ALGORITHM=UNDEFINED */
/*!50013 SQL SECURITY DEFINER */
/*!50001 VIEW `ml_items` AS select `items`.`id` AS `id`,`items`.`ml_item_id` AS `ml_item_id`,`items`.`account_id` AS `account_id`,`items`.`title` AS `title`,`items`.`category_id` AS `category_id`,`items`.`price` AS `price`,`items`.`currency_id` AS `currency_id`,`items`.`available_quantity` AS `available_quantity`,`items`.`status` AS `status`,`items`.`condition_type` AS `condition_type`,`items`.`catalog_product_id` AS `catalog_product_id`,`items`.`data` AS `data`,`items`.`created_at` AS `created_at`,`items`.`updated_at` AS `updated_at` from `items` */;
/*!50001 SET character_set_client      = @saved_cs_client */;
/*!50001 SET character_set_results     = @saved_cs_results */;
/*!50001 SET collation_connection      = @saved_col_connection */;
/*!50001 DROP VIEW IF EXISTS `neural_network_summary`*/;
/*!50001 SET @saved_cs_client          = @@character_set_client */;
/*!50001 SET @saved_cs_results         = @@character_set_results */;
/*!50001 SET @saved_col_connection     = @@collation_connection */;
/*!50001 SET character_set_client      = utf8mb4 */;
/*!50001 SET character_set_results     = utf8mb4 */;
/*!50001 SET collation_connection      = utf8mb4_0900_ai_ci */;
/*!50001 CREATE ALGORITHM=UNDEFINED */
/*!50013 SQL SECURITY DEFINER */
/*!50001 VIEW `neural_network_summary` AS select `nn`.`account_id` AS `account_id`,`nn`.`network_type` AS `network_type`,`nn`.`network_name` AS `network_name`,`nn`.`version` AS `version`,`nn`.`is_active` AS `is_active`,count(`th`.`id`) AS `training_steps`,avg(`th`.`loss_value`) AS `avg_loss`,min(`th`.`loss_value`) AS `best_loss`,max(`th`.`training_step`) AS `latest_step`,`nn`.`target_network_updated` AS `target_network_updated` from (`neural_networks` `nn` left join `training_history` `th` on((`nn`.`id` = `th`.`network_id`))) group by `nn`.`id`,`nn`.`account_id`,`nn`.`network_type`,`nn`.`network_name`,`nn`.`version`,`nn`.`is_active`,`nn`.`target_network_updated` */;
/*!50001 SET character_set_client      = @saved_cs_client */;
/*!50001 SET character_set_results     = @saved_cs_results */;
/*!50001 SET collation_connection      = @saved_col_connection */;
/*!50001 DROP VIEW IF EXISTS `v_latest_governance_diagnostic`*/;
/*!50001 SET @saved_cs_client          = @@character_set_client */;
/*!50001 SET @saved_cs_results         = @@character_set_results */;
/*!50001 SET @saved_col_connection     = @@collation_connection */;
/*!50001 SET character_set_client      = utf8mb4 */;
/*!50001 SET character_set_results     = utf8mb4 */;
/*!50001 SET collation_connection      = utf8mb4_0900_ai_ci */;
/*!50001 CREATE ALGORITHM=UNDEFINED */
/*!50013 SQL SECURITY DEFINER */
/*!50001 VIEW `v_latest_governance_diagnostic` AS select `gdh`.`id` AS `id`,`gdh`.`account_id` AS `account_id`,`gdh`.`account_status` AS `account_status`,`gdh`.`total_items` AS `total_items`,`gdh`.`healthy_items` AS `healthy_items`,`gdh`.`problem_items` AS `problem_items`,`gdh`.`critical_actions` AS `critical_actions`,`gdh`.`top_causes` AS `top_causes`,`gdh`.`executive_summary` AS `executive_summary`,`gdh`.`full_result` AS `full_result`,`gdh`.`created_at` AS `created_at` from (`governance_diagnostic_history` `gdh` join (select `governance_diagnostic_history`.`account_id` AS `account_id`,max(`governance_diagnostic_history`.`created_at`) AS `max_created` from `governance_diagnostic_history` group by `governance_diagnostic_history`.`account_id`) `latest` on(((`gdh`.`account_id` = `latest`.`account_id`) and (`gdh`.`created_at` = `latest`.`max_created`)))) */;
/*!50001 SET character_set_client      = @saved_cs_client */;
/*!50001 SET character_set_results     = @saved_cs_results */;
/*!50001 SET collation_connection      = @saved_col_connection */;
/*!50001 DROP VIEW IF EXISTS `v_performance_summary`*/;
/*!50001 SET @saved_cs_client          = @@character_set_client */;
/*!50001 SET @saved_cs_results         = @@character_set_results */;
/*!50001 SET @saved_col_connection     = @@collation_connection */;
/*!50001 SET character_set_client      = utf8mb4 */;
/*!50001 SET character_set_results     = utf8mb4 */;
/*!50001 SET collation_connection      = utf8mb4_0900_ai_ci */;
/*!50001 CREATE ALGORITHM=UNDEFINED */
/*!50013 SQL SECURITY DEFINER */
/*!50001 VIEW `v_performance_summary` AS select 'queries' AS `metric_type`,count(0) AS `total_count`,round((avg(`query_log`.`duration`) * 1000),2) AS `avg_ms`,round((max(`query_log`.`duration`) * 1000),2) AS `max_ms`,sum((case when (`query_log`.`duration` > 1.0) then 1 else 0 end)) AS `slow_count`,cast(`query_log`.`created_at` as date) AS `date` from `query_log` where (`query_log`.`created_at` > (now() - interval 7 day)) group by cast(`query_log`.`created_at` as date) union all select 'api_calls' AS `metric_type`,count(0) AS `total_count`,round((avg(`api_metrics`.`response_time`) * 1000),2) AS `avg_ms`,round((max(`api_metrics`.`response_time`) * 1000),2) AS `max_ms`,sum((case when (`api_metrics`.`status_code` >= 400) then 1 else 0 end)) AS `slow_count`,cast(`api_metrics`.`created_at` as date) AS `date` from `api_metrics` where (`api_metrics`.`created_at` > (now() - interval 7 day)) group by cast(`api_metrics`.`created_at` as date) */;
/*!50001 SET character_set_client      = @saved_cs_client */;
/*!50001 SET character_set_results     = @saved_cs_results */;
/*!50001 SET collation_connection      = @saved_col_connection */;
/*!50001 DROP VIEW IF EXISTS `v_seo_active_keywords`*/;
/*!50001 SET @saved_cs_client          = @@character_set_client */;
/*!50001 SET @saved_cs_results         = @@character_set_results */;
/*!50001 SET @saved_col_connection     = @@collation_connection */;
/*!50001 SET character_set_client      = utf8mb4 */;
/*!50001 SET character_set_results     = utf8mb4 */;
/*!50001 SET collation_connection      = utf8mb4_0900_ai_ci */;
/*!50001 CREATE ALGORITHM=UNDEFINED */
/*!50013 SQL SECURITY DEFINER */
/*!50001 VIEW `v_seo_active_keywords` AS select `seo_keyword_cache`.`category_id` AS `category_id`,`seo_keyword_cache`.`keyword` AS `keyword`,`seo_keyword_cache`.`type` AS `type`,`seo_keyword_cache`.`weight` AS `weight`,`seo_keyword_cache`.`source` AS `source`,`seo_keyword_cache`.`expires_at` AS `expires_at` from `seo_keyword_cache` where ((`seo_keyword_cache`.`is_valid` = 1) and ((`seo_keyword_cache`.`expires_at` is null) or (`seo_keyword_cache`.`expires_at` > now()))) */;
/*!50001 SET character_set_client      = @saved_cs_client */;
/*!50001 SET character_set_results     = @saved_cs_results */;
/*!50001 SET collation_connection      = @saved_col_connection */;
/*!50001 DROP VIEW IF EXISTS `v_seo_synonym_summary`*/;
/*!50001 SET @saved_cs_client          = @@character_set_client */;
/*!50001 SET @saved_cs_results         = @@character_set_results */;
/*!50001 SET @saved_col_connection     = @@collation_connection */;
/*!50001 SET character_set_client      = utf8mb4 */;
/*!50001 SET character_set_results     = utf8mb4 */;
/*!50001 SET collation_connection      = utf8mb4_0900_ai_ci */;
/*!50001 CREATE ALGORITHM=UNDEFINED */
/*!50013 SQL SECURITY DEFINER */
/*!50001 VIEW `v_seo_synonym_summary` AS select `seo_synonym_hierarchy`.`category_id` AS `category_id`,`seo_synonym_hierarchy`.`level` AS `level`,count(0) AS `total_synonyms`,avg(`seo_synonym_hierarchy`.`weight`) AS `avg_weight`,`seo_synonym_hierarchy`.`destination` AS `destination` from `seo_synonym_hierarchy` where (`seo_synonym_hierarchy`.`is_active` = 1) group by `seo_synonym_hierarchy`.`category_id`,`seo_synonym_hierarchy`.`level`,`seo_synonym_hierarchy`.`destination` */;
/*!50001 SET character_set_client      = @saved_cs_client */;
/*!50001 SET character_set_results     = @saved_cs_results */;
/*!50001 SET collation_connection      = @saved_col_connection */;
/*!50001 DROP VIEW IF EXISTS `vw_ean_balance_summary`*/;
/*!50001 SET @saved_cs_client          = @@character_set_client */;
/*!50001 SET @saved_cs_results         = @@character_set_results */;
/*!50001 SET @saved_col_connection     = @@collation_connection */;
/*!50001 SET character_set_client      = utf8mb4 */;
/*!50001 SET character_set_results     = utf8mb4 */;
/*!50001 SET collation_connection      = utf8mb4_general_ci */;
/*!50001 CREATE ALGORITHM=UNDEFINED */
/*!50013 SQL SECURITY DEFINER */
/*!50001 VIEW `vw_ean_balance_summary` AS select `a`.`id` AS `account_id`,`a`.`nickname` AS `nickname`,coalesce(`b`.`total_purchased`,0) AS `total_purchased`,coalesce(`b`.`total_used`,0) AS `total_used`,coalesce(`b`.`available`,0) AS `available`,`b`.`last_purchase_at` AS `last_purchase_at` from (`ml_accounts` `a` left join `ean_balances` `b` on((`a`.`id` = `b`.`account_id`))) */;
/*!50001 SET character_set_client      = @saved_cs_client */;
/*!50001 SET character_set_results     = @saved_cs_results */;
/*!50001 SET collation_connection      = @saved_col_connection */;
/*!50001 DROP VIEW IF EXISTS `vw_ean_inventory_summary`*/;
/*!50001 SET @saved_cs_client          = @@character_set_client */;
/*!50001 SET @saved_cs_results         = @@character_set_results */;
/*!50001 SET @saved_col_connection     = @@collation_connection */;
/*!50001 SET character_set_client      = utf8mb4 */;
/*!50001 SET character_set_results     = utf8mb4 */;
/*!50001 SET collation_connection      = utf8mb4_general_ci */;
/*!50001 CREATE ALGORITHM=UNDEFINED */
/*!50013 SQL SECURITY DEFINER */
/*!50001 VIEW `vw_ean_inventory_summary` AS select `ean_inventory`.`status` AS `status`,count(0) AS `quantity`,`ean_inventory`.`purchase_batch` AS `purchase_batch` from `ean_inventory` group by `ean_inventory`.`status`,`ean_inventory`.`purchase_batch` */;
/*!50001 SET character_set_client      = @saved_cs_client */;
/*!50001 SET character_set_results     = @saved_cs_results */;
/*!50001 SET collation_connection      = @saved_col_connection */;
/*!50001 DROP VIEW IF EXISTS `vw_ean_sales_summary`*/;
/*!50001 SET @saved_cs_client          = @@character_set_client */;
/*!50001 SET @saved_cs_results         = @@character_set_results */;
/*!50001 SET @saved_col_connection     = @@collation_connection */;
/*!50001 SET character_set_client      = utf8mb4 */;
/*!50001 SET character_set_results     = utf8mb4 */;
/*!50001 SET collation_connection      = utf8mb4_general_ci */;
/*!50001 CREATE ALGORITHM=UNDEFINED */
/*!50013 SQL SECURITY DEFINER */
/*!50001 VIEW `vw_ean_sales_summary` AS select cast(`ean_purchases`.`created_at` as date) AS `sale_date`,count(0) AS `total_orders`,sum(`ean_purchases`.`quantity`) AS `total_eans`,sum(`ean_purchases`.`total_amount`) AS `total_revenue`,sum((case when (`ean_purchases`.`payment_status` = 'paid') then `ean_purchases`.`total_amount` else 0 end)) AS `paid_revenue` from `ean_purchases` group by cast(`ean_purchases`.`created_at` as date) */;
/*!50001 SET character_set_client      = @saved_cs_client */;
/*!50001 SET character_set_results     = @saved_cs_results */;
/*!50001 SET collation_connection      = @saved_col_connection */;
/*!50112 SET @disable_bulk_load = IF (@is_rocksdb_supported, 'SET SESSION rocksdb_bulk_load = @old_rocksdb_bulk_load', 'SET @dummy_rocksdb_bulk_load = 0') */;
/*!50112 PREPARE s FROM @disable_bulk_load */;
/*!50112 EXECUTE s */;
/*!50112 DEALLOCATE PREPARE s */;
/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;

/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
/*!40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS */;
/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
/*!40111 SET SQL_NOTES=@OLD_SQL_NOTES */;


DROP TABLE IF EXISTS `ml_sales_blockers`;
CREATE TABLE `ml_sales_blockers` (
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
