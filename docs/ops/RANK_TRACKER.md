# Rank Tracker — Onda 4.1 (fix/onda4-1-rank)

## Política (inegociável)

- Somente **API oficial** `api.mercadolibre.com`.
- **Proibido**: scraping HTML, headless contra o site ML, rotação de IP para burlar ASN.
- Tokens ML **nunca** passam pelo coletor local (T1b).
- `ML_WRITE_AUTOMATION=false` intacto.

## Evidência T1a — busca autenticada (2026-08-10)

Egress host: `72.62.14.91` (datacenter). Token user-scoped renovado com sucesso (`/users/me` 200).

| Tentativa | Request (sem token no log) | HTTP | Resultado |
|-----------|----------------------------|------|-----------|
| A | `GET /sites/MLB/search?q=…&limit=1` **Bearer user OAuth** (`public=false`) + UA app | **403** `forbidden` | falhou |
| B | idem `public=true` / app-only | **403** | falhou |
| C | cURL anônimo raw | **403** | falhou |

**Conclusão T1a:** 403 persiste mesmo autenticado → **não** habilitar search neste egress. Seguir T1b + T1c.

Arquivo bruto: `docs/ops/evidence/t1a-search-probe-20260810.json` (gerado no smoke).

## T1b — Coletor local residencial

- Script: `collector/rank-collector.php` (<200 linhas)
- README + install Linux/Windows em `collector/`
- Endpoints: `GET /api/rank/assignments`, `POST /api/rank/ingest` (chave `RANK_COLLECTOR_KEY` + HMAC)
- Flag: `RANK_COLLECTOR_LOCAL=false` → degradado elegante
- Volume: máx 30 kw/dia, 1 req/2s
- `position_source=proxy`

## T1c — Fallback trends/highlights (sempre)

Deste host, **com** OAuth user-scoped:

| Endpoint | Auth | HTTP |
|----------|------|------|
| `GET /trends/MLB` | Bearer | **200** (lista) |
| `GET /trends/MLB/{category}` | Bearer | **200** |
| `GET /highlights/MLB/category/{id}` | Bearer | **200** |
| `GET /trends/MLB` | anônimo | **403** PolicyAgent |

`position_source=trends` · Pregão mostra **trends parcial (sem posição exata)** em vez de N/D genérico.

## Flags

```
RANK_TRACKER_ENABLED=false          # search neste host continua 403
RANK_COLLECTOR_LOCAL=false          # ligar só se o usuário agendar o coletor
RANK_COLLECTOR_KEY=                 # >=16 chars
RANK_COLLECTOR_HMAC_SECRET=         # default = KEY se vazio no coletor
```

## Cadência diária (servidor)

```bash
# T1c leve (trends/highlights autenticados) — já no crontab 05:20
php bin/rank-tracker-collect.php --account-id=1335 --demand-only

# Search completo só na janela 04–06h (ou --force); neste host ainda 403
php bin/rank-tracker-collect.php --account-id=1335 --force
```

## Reabilitar search (futuro)

1. Smoke: `GET /sites/MLB/search` autenticado = 200 com `results`
2. `RANK_TRACKER_ENABLED=true`
3. `php bin/rank-tracker-collect.php --account-id=… --force`
