<?php

declare(strict_types=1);

/**
 * Configuração do Pregão ESKILL (read-only).
 *
 * PREGAO_SEED=false em produção — seeds só quando explicitamente habilitado.
 * RANK_TRACKER_ENABLED=false — busca orgânica sites/search bloqueada neste host.
 * PREGAO_KEYWORDS — lista CSV (só usada se RANK_TRACKER_ENABLED=true).
 */
return [
    'seed_enabled' => filter_var($_ENV['PREGAO_SEED'] ?? getenv('PREGAO_SEED') ?: 'false', FILTER_VALIDATE_BOOLEAN),
    'rank_tracker_enabled' => filter_var(
        $_ENV['RANK_TRACKER_ENABLED'] ?? getenv('RANK_TRACKER_ENABLED') ?: 'false',
        FILTER_VALIDATE_BOOLEAN
    ),
    'default_account_id' => (int) ($_ENV['PREGAO_ACCOUNT_ID'] ?? getenv('PREGAO_ACCOUNT_ID') ?: 1335),
    'keywords' => array_values(array_filter(array_map(
        static fn (string $k): string => trim($k),
        explode(',', (string) ($_ENV['PREGAO_KEYWORDS'] ?? getenv('PREGAO_KEYWORDS') ?: 'portao pet,grade protecao bebe,cerca pet,portao bebe,cerca bebe'))
    ))),
    'keyword_search_limit' => 50,
    'keyword_search_pages' => 4,
    'semaforo_limites' => [
        'reclamacoes_pct' => 2.0,
        'atrasos_pct' => 15.0,
        'cancelamentos_pct' => 2.5,
    ],
    /** Freshness do coletor Ads no tick (segundos). Override: ADS_COLLECT_FRESHNESS_TTL */
    'ads_collect_freshness_ttl' => (int) ($_ENV['ADS_COLLECT_FRESHNESS_TTL']
        ?? getenv('ADS_COLLECT_FRESHNESS_TTL')
        ?: 300),
    /** Idade máxima para preservar Ft em stale (segundos). Override: ADS_MAX_STALE_AGE */
    'ads_max_stale_age' => (int) ($_ENV['ADS_MAX_STALE_AGE']
        ?? getenv('ADS_MAX_STALE_AGE')
        ?: 3600),
];
