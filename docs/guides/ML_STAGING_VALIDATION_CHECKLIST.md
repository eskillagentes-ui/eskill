# Checklist de Validação em Staging — Mercado Livre

## Objetivo

Garantir que a integração Mercado Livre esteja apta para promoção à produção com validação funcional, operacional e observável.

## Pré-requisitos

- ambiente isolado provisionado (`docs/ops/STAGING.md`): path, MySQL `eskill_staging`, Redis DB `1`, WS `:8092`
- `bash scripts/deploy_staging.sh --check` exit 0
- credenciais reais ou controladas de staging
- `ML_APP_ID`, `ML_CLIENT_SECRET`, `ML_REDIRECT_URI` e `APP_KEY` válidos (**APP_KEY distinta da prod**)
- `ML_REDIRECT_URI=https://staging.eskill.com.br/auth/callback` cadastrado na app ML
- `ML_WEBHOOK_SECRET` configurado (HMAC de webhooks)
- webhook de staging apontado para o ambiente correto
- banco e logs segregados, migrations aplicadas
- feature flags de mutação habilitadas conforme o escopo do teste
- **nunca** `PREGAO_ACCOUNT_ID=1335` no `.env` staging
- DNS/TLS públicos de `staging.eskill.com.br` ativos (ou `/etc/hosts` só para smoke local)

## Verificação automatizada (pré-condição de todos os gates)

```bash
# Execução básica com saída human-readable
php bin/ml-health-check.php \
  --app-url=https://staging.eskill.com.br \
  --api-token="$ML_HEALTHCHECK_API_TOKEN" \
  --all-accounts

# Saída JSON para log de release
php bin/ml-health-check.php --json \
  --app-url=https://staging.eskill.com.br \
  --api-token="$ML_HEALTHCHECK_API_TOKEN" \
  2>&1 | tee /tmp/staging-health-$(date +%Y%m%d%H%M).json
echo "Exit code: $?"
```

**O exit code deve ser `0`.** Qualquer exit code `1` bloqueia a promoção.

## Gate 1 — Configuração

- [ ] `php bin/ml-health-check.php --json` exit code `0` (sem erro crítico)
- [ ] `/api/auth/oauth-config-status` retorna `ready=true`
- [ ] `ML_REDIRECT_URI` aponta para `/auth/callback`
- [ ] `APP_KEY` válida e sem placeholder
- [ ] `ML_WEBHOOK_SECRET` configurado (✅ no health check: "HMAC habilitado")
- [ ] Tabela `webhook_event_inbox` acessível (✅ no health check: "Tabela webhook_event_inbox acessível")
- [ ] nenhum proxy inválido herdado por workers

## Gate 2 — OAuth

- [ ] fluxo `/auth/authorize` inicia corretamente
- [ ] callback persiste a conta em `ml_accounts`
- [ ] `/users/me` valida a conta após o callback
- [ ] refresh token funciona
- [ ] `invalid_grant` leva a estado de reconexão controlado

## Gate 3 — Itens

- [ ] sync inicial de itens completa sem erro crítico
- [ ] sync incremental não duplica registros
- [ ] preço, estoque e status reconciliam corretamente
- [ ] logs por endpoint/conta estão presentes

## Gate 4 — Pedidos

- [ ] pedido novo entra no sistema
- [ ] atualização de status reconcilia localmente
- [ ] vínculo com envio/pagamento permanece consistente

## Gate 5 — Perguntas

- [ ] perguntas são recebidas por webhook ou polling compensatório
- [ ] respostas são auditadas
- [ ] falhas de resposta ficam registradas com `error_type`

## Gate 6 — Envios

- [ ] tracking é sincronizado
- [ ] mudanças de status logístico atualizam o estado local

## Gate 7 — Webhooks

- [ ] assinatura HMAC (`ML_WEBHOOK_SECRET`) e validação temporal funcionam
- [ ] requisição com `x-signature` inválido retorna 401/403
- [ ] evento duplicado não gera mutação duplicada (`webhook_event_inbox` deduplication)
- [ ] inbox persiste evento antes do processamento
- [ ] replay de falha funciona (`bin/ml-webhook-processor.php` / replay service)
- [ ] backlog permanece dentro do limite operacional
- [ ] cron `ml-webhook-processor.php` agendado no crontab (`crontab -l | grep ml-webhook-processor`)

## Gate 8 — Rate Limit e Erros

- [ ] 429 respeita `Retry-After`
- [ ] timeouts são classificados corretamente
- [ ] falhas de proxy, SSL e autenticação geram logs estruturados
- [ ] `invalid_grant` não entra em retry infinito

## Gate 9 — Observabilidade

- [ ] logs estruturados presentes para OAuth, API client, workers e webhooks
- [ ] métricas de sucesso/falha por endpoint disponíveis
- [ ] health checks executam sem travar o ambiente
- [ ] alertas críticos estão configurados

## Gate 10 — Operação Assíncrona

- [ ] não há duplicidade operacional entre workers de sync
- [ ] cron executa o conjunto esperado
- [ ] locks/claims impedem concorrência destrutiva

## Gate 11 — Smoke Tests

```bash
# Isolamento do stack staging
bash scripts/deploy_staging.sh --check

# E2E completo no staging (inclui 31 POST/DELETE — gate de promoção)
npm run test:e2e:staging

# Em produção / host compartilhado: NÃO rodar a suíte mutante
npm run test:e2e:readonly

# Testes unitários do namespace ML
php vendor/bin/phpunit --testsuite=Unit --filter=MercadoLivre

# Suite completa
php vendor/bin/phpunit --testsuite=Unit
```

- [ ] `deploy_staging.sh --check` exit 0 (Redis DB1 sem leak no DB0; health staging 200)
- [ ] `npm run test:e2e:staging` verde
- [ ] todos os testes `MercadoLivre*Test` passando (0 failures)
- [ ] `MercadoLivreWebhookReplayServiceTest` — todos passing
- [ ] `WebhookInboxServiceTest` — todos passing
- [ ] nenhum regression nos demais testes Unit

## Critério de Promoção

Promover para produção apenas quando:

- `bash scripts/deploy_staging.sh --check` exit code `0`
- `npm run test:e2e:staging` verde
- `php bin/ml-health-check.php --json --app-url=https://staging.eskill.com.br` retornar exit code `0`
- todos os gates P0 (Gate 1, 2, 7 e 11) estiverem aprovados
- não houver erro crítico aberto
- reconexão OAuth, webhook inbox e sync core estiverem validados
- documentação operacional estiver atualizada (`docs/ops/STAGING.md`)
- em produção, apenas `npm run test:e2e:readonly` + health (proibido smoke seed / E2E mutante)

## Assinatura de Release

| Campo | Valor |
| --- | --- |
| Release / branch | _[preencher]_ |
| Data da validação | _[preencher]_ |
| Health check exit code | _[0 = OK / 1 = FAIL]_ |
| Responsável | _[preencher]_ |
| Aprovado para produção? | ⬜ **SIM** / ⬜ **NÃO** |
