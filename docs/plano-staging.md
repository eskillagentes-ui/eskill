# Plano — staging isolado (Pregão / Hermes)

> **Status:** plano apenas — **não provisionar** nesta tarefa.
> Detalhe operacional já esboçado em [`docs/ops/STAGING.md`](ops/STAGING.md).

## Objetivo

Ambiente paralelo para smoke Hermes, `PREGAO_SEED=true` e E2E mutante
(`E2E_ALLOW_MUTATION=true`), sem contaminar produção (conta ML **1335**, MySQL
`meli`, Redis DB 0).

## Isolamento proposto

| Recurso | Produção | Staging |
|---------|----------|---------|
| Path | `/home/eskill/htdocs/eskill.com.br` | `/home/eskill/htdocs/staging.eskill.com.br` |
| Domínio / porta | `eskill.com.br` :443 | `staging.eskill.com.br` :443 (+ WS `:8092`) |
| `APP_ENV` | `production` | `staging` |
| MySQL | `meli` | banco **separado** `eskill_staging` (user próprio) |
| Redis | DB `0`, canal `pregao` | DB `1`, namespace/prefixo `staging:` + canal `pregao` |
| Conta ML | 1335 (FACILYTY) | usuário de **teste** ≠1335 |
| `PREGAO_SEED` | `false` | `true` |
| `ML_WRITE_AUTOMATION` | política prod | `false` |
| Tick / WS | `pregao-tick`, `:8091` | units `*-staging` (tick disabled até OAuth teste) |

Regras:

- Zero sync staging → prod.
- Schema staging via dump `--no-data` (sem tokens).
- `APP_KEY` distinta (sessões/tokens não cruzam).

## Fluxo de deploy proposto

```text
feature/* ──► PR ──► CI unitário
                │
                ▼
         merge → staging
                │
                ▼
         QA Hermes (read-only + smoke seed)
                │
                ▼
         approve ──► merge main/feature estável
                │
                ▼
         deploy produção (bin/deploy.sh)
                │
                ▼
         npm run test:e2e:readonly  (sem mutação)
```

1. Branch de feature no GitHub.
2. PR com checks verdes (PHPUnit Pregão + lint).
3. Deploy em staging (`scripts/deploy_staging.sh` — quando provisionado).
4. Hermes roda suite em staging; seed permitido.
5. Merge aprovado → deploy produção.
6. Em produção: somente `test:e2e:readonly`; mutantes exigem `E2E_ALLOW_MUTATION=true`.

## Pré-requisitos humanos (quando executar)

1. DNS `staging.eskill.com.br` → IP do host.
2. TLS (certbot) + nginx vhost.
3. Criar DB/user MySQL staging + Redis DB 1.
4. OAuth ML: redirect `https://staging.eskill.com.br/auth/callback` + conta ≠1335.
5. Só então habilitar `pregao-tick-staging`.

## Fora de escopo deste plano

- Provisionar DNS/TLS/DB/Redis.
- Copiar tokens de produção.
- Ligar tick staging apontando para 1335.
