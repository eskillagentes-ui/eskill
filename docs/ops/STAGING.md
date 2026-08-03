# Staging isolado — `staging.eskill.com.br`

Ambiente paralelo no **mesmo host** de produção para smoke Hermes, seed do Pregão e E2E mutante (POST/DELETE), sem contaminar a conta **1335** / MySQL prod / Redis DB 0.

## Mapa

| Recurso | Produção | Staging |
|---------|----------|---------|
| Path | `/home/eskill/htdocs/eskill.com.br` | `/home/eskill/htdocs/staging.eskill.com.br` |
| `APP_ENV` | `production` | `staging` |
| MySQL | `meli` | `eskill_staging` (user `eskill_staging`) |
| Redis | DB `0` | DB `1` |
| WS Pregão | `:8091` (`pregao-ws`) | `:8092` (`pregao-ws-staging`) |
| Tick | `pregao-tick` conta 1335 | `pregao-tick-staging` **disabled** até conta teste ≠1335 |
| `PREGAO_SEED` | `false` | `true` |
| `ML_WRITE_AUTOMATION` | política prod | `false` |
| `APP_KEY` | prod | **distinta** (tokens não cruzam) |

Regra: **zero sync staging→prod**. Schema staging veio de dump `--no-data` (sem tokens).

`.env` staging: owner `eskill:eskill`, mode `640` (PHP-FPM precisa ler; `600` root quebra `/api/health`).

## Pré-requisitos humanos

1. **DNS** — `staging.eskill.com.br` A → IP do host (hoje só `eskill.com.br` resolve).
2. **TLS** — após DNS: `certbot --nginx -d staging.eskill.com.br` e descomentar bloco 443 em [`config/nginx/staging.eskill.com.br.conf`](../config/nginx/staging.eskill.com.br.conf).
3. **OAuth ML** — adicionar `https://staging.eskill.com.br/auth/callback` na app ML (ou app de teste) e conectar conta **≠1335**; setar `PREGAO_ACCOUNT_ID` no `.env` staging.
4. Só então: `systemctl enable --now pregao-tick-staging`.

## Comandos

```bash
# Verificar isolamento + smoke Redis/MySQL
bash scripts/deploy_staging.sh --check

# Sync código do repo prod → path staging + nginx + WS
bash scripts/deploy_staging.sh

# E2E
# Staging (suíte completa, incl. 31 POST/DELETE — gate de promoção):
npm run test:e2e:staging

# Prod / host compartilhado (somente readonly):
npm run test:e2e:readonly

# Health ML no staging (após OAuth)
php bin/ml-health-check.php --app-url=https://staging.eskill.com.br --all-accounts
```

### Gates de promoção (ML / Pregão)

1. `bash scripts/deploy_staging.sh --check` exit 0  
2. `npm run test:e2e:staging` verde  
3. `php bin/ml-health-check.php --app-url=https://staging.eskill.com.br` exit 0 (após OAuth)  
4. Em produção: apenas `npm run test:e2e:readonly` + health — **proibido** smoke seed / E2E mutante

Configs versionadas:

- [`config/nginx/staging.eskill.com.br.conf`](../config/nginx/staging.eskill.com.br.conf)
- [`config/systemd/pregao-ws-staging.service`](../config/systemd/pregao-ws-staging.service)
- [`config/systemd/pregao-tick-staging.service`](../config/systemd/pregao-tick-staging.service)
- [`scripts/deploy_staging.sh`](../scripts/deploy_staging.sh)

Checklist ML: [`docs/guides/ML_STAGING_VALIDATION_CHECKLIST.md`](../guides/ML_STAGING_VALIDATION_CHECKLIST.md)

## Smoke seed (só staging)

```bash
cd /home/eskill/htdocs/staging.eskill.com.br
PREGAO_SEED=true php -r '
require "vendor/autoload.php";
Dotenv\Dotenv::createImmutable(__DIR__)->safeLoad();
pregao_emit_sale(["order_id"=>"T1","valor"=>199.9,"titulo"=>"Teste Hermes","sku"=>"MLB1"], (int)($_ENV["PREGAO_ACCOUNT_ID"] ?: 0), "seed");
'
```

Não rode isso no path de produção.
