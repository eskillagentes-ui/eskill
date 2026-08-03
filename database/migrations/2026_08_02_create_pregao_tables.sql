-- Pregão: eventos ao vivo, índice ESKL11 e ranks de keyword (read-only UI)
-- MySQL: JSON (equivalente ao JSONB do contrato)

CREATE TABLE IF NOT EXISTS `pregao_events` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `account_id` int DEFAULT NULL,
  `type` varchar(64) NOT NULL,
  `ts` datetime(3) NOT NULL,
  `payload` json NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_pregao_events_type_ts` (`type`, `ts`),
  KEY `idx_pregao_events_account_ts` (`account_id`, `ts`),
  KEY `idx_pregao_events_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `account_index_daily` (
  `account_id` int NOT NULL,
  `date` date NOT NULL,
  `o` decimal(12,4) NOT NULL,
  `h` decimal(12,4) NOT NULL,
  `l` decimal(12,4) NOT NULL,
  `c` decimal(12,4) NOT NULL,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`account_id`, `date`),
  KEY `idx_account_index_daily_date` (`date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `keyword_ranks` (
  `account_id` int NOT NULL,
  `kw` varchar(255) NOT NULL,
  `date` date NOT NULL,
  `pos` int NOT NULL,
  `delta` int DEFAULT NULL,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`account_id`, `kw`, `date`),
  KEY `idx_keyword_ranks_date` (`date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `account_index_baselines` (
  `account_id` int NOT NULL,
  `vendas_7d_baseline` decimal(12,4) NOT NULL DEFAULT 1.0000,
  `pos_baseline` decimal(12,4) NOT NULL DEFAULT 10.0000,
  `tacos_baseline` decimal(12,4) NOT NULL DEFAULT 10.0000,
  `recalculated_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`account_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `account_index_metrics` (
  `account_id` int NOT NULL,
  `vendas_hoje` int NOT NULL DEFAULT 0,
  `receita_hoje` decimal(14,2) NOT NULL DEFAULT 0.00,
  `ticket_medio` decimal(14,2) NOT NULL DEFAULT 0.00,
  `vendas_7d` decimal(12,4) NOT NULL DEFAULT 0.0000,
  `tacos` decimal(12,4) NOT NULL DEFAULT 0.0000,
  `posicao_media` decimal(12,4) NOT NULL DEFAULT 10.0000,
  `health_medio` decimal(6,4) NOT NULL DEFAULT 0.0000,
  `reputacao_cor` varchar(32) NOT NULL DEFAULT 'verde',
  `reclamacoes_pct` decimal(8,4) NOT NULL DEFAULT 0.0000,
  `atrasos_pct` decimal(8,4) NOT NULL DEFAULT 0.0000,
  `cancelamentos_pct` decimal(8,4) NOT NULL DEFAULT 0.0000,
  `perguntas_hoje` int NOT NULL DEFAULT 0,
  `tempo_medio_resposta_s` int NOT NULL DEFAULT 0,
  `acoes_hora` int NOT NULL DEFAULT 0,
  `indice_atual` decimal(12,4) NOT NULL DEFAULT 1000.0000,
  `semaforo_status` varchar(16) NOT NULL DEFAULT 'verde',
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`account_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
