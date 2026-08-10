# Rank Tracker — causa da desativação e política Onda 4

## Causa exata (não é scraping HTML)

O Pregão exibia **"Posição orgânica: N/D — desativado por segurança"** / **"rank tracker desativado"** porque:

1. Flag `RANK_TRACKER_ENABLED=false` (default em `.env`, `config/pregao.php` e units systemd `pregao-tick` / `pregao-keywords`).
2. A implementação **sempre** usou a **API oficial** `GET /sites/{site}/search` (`MercadoLivreClient::searchItems` / `PregaoMetricsCollector::collectKeywords`).
3. Neste host (IP de datacenter / Cloudflare PolicyAgent) o endpoint responde **HTTP 403 `forbidden`** de forma consistente — com ou sem `access_token`, com ou sem `client_id`. Comentário no client:

   > `/sites/{site}/search 403 genérico (forbidden) — IP datacenter/CF; esperado.`

4. **Scraping HTML do site do ML não faz parte deste caminho.** O `ProxyService` / scraping foi removido do repo em outro contexto (auditoria AWA 2026-08-03). O rank tracker nunca dependeu de HTML.

## O que a Onda 4 faz

- `RankTrackerService`: keywords por anúncio, tabela `rank_history`, rate limit, cache diário, backoff 429, circuit breaker.
- CLI `bin/rank-tracker-collect.php` (janela 04h–06h recomendada).
- Pregão/UI consomem status real (`circuit_open` / `search_forbidden` / capturas).
- Enquanto o 403 persistir neste egress, **não haverá posições reais**; o circuit abre após 3 falhas e o Pregão permanece indisponível com motivo explícito — sem inventar dados.

## Decisão arquitetural (Onda 4)

Enquanto `/sites/MLB/search` retornar **403** neste egress:

1. **Não inventamos posições.** `rank_history` pode registrar tentativas com `position=NULL` e `error=search_forbidden` (auditoria).
2. Circuit breaker abre após N falhas → Pregão mostra **INDISPONÍVEL** com reason `circuit_open` / causa documentada (não "N/D genérico").
3. Keywords são derivadas e persistidas em `item_rank_keywords` mesmo sem posição.
4. Habilitação de `RANK_TRACKER_ENABLED=true` em produção só após API liberar (curl de smoke).

Cadência: coleta diária **04h–06h** America/Sao_Paulo (`bin/rank-tracker-collect.php`), máx. `RANK_TRACKER_MAX_REQ_PER_MIN` (default 6), cache 1 captura/kw/mlb/dia.


1. Confirmar `curl`/`MercadoLivreClient` a `/sites/MLB/search?q=teste&limit=1` retorna `results` (não 403).
2. `RANK_TRACKER_ENABLED=true` no `.env`.
3. Rodar `php bin/rank-tracker-collect.php --account-id=1335`.
4. Religar `pregao-keywords.timer` se desejado.
