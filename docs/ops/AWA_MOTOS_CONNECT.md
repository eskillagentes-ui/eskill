# Conectar segunda conta ML — AWA Motos

Checklist operacional (Onda 3.5 / T4). **Não conecte a conta por API automática** — o dono faz o login ML pessoalmente.

## O que já existe (pronto)

- Tela `/dashboard/accounts` com botão **Conectar Nova Conta** → `/auth/authorize`
- Mesmo fluxo em Configurações (`/dashboard/settings`) e perfil
- OAuth: `AuthController::authorize` + `callback` via `MercadoLivreAuthService`
  - `state` amarrado ao `user_id` da sessão
  - Reconexão: `/auth/authorize?reconnect={account_id}`
  - `redirect_uri` = valor configurado no app ML (`ML_REDIRECT_URI` no `.env` de produção)
- Multi-conta parcial: seletor de conta no topo; filtros `account_id` em items/orders/questions
- Isolamento: services principais filtram por `account_id` / `ml_account_id`

## Passo a passo (você executa)

1. Em produção, confirme no painel do app ML (developers.mercadolivre.com.br) que a URL de redirect autorizada é exatamente a de produção (`https://eskill.com.br/auth/callback` ou a configurada em `ML_REDIRECT_URI`).
2. Faça login no eskill.com.br com o usuário admin.
3. Abra **Contas** → **Conectar Nova Conta**.
4. No Mercado Livre, autentique com a conta **AWA Motos** (não FACILYTY).
5. Autorize o aplicativo.
6. Volte ao dashboard — a nova conta deve aparecer em `/dashboard/accounts` com status ativo e nickname AWA.

## Checklist pós-conexão

- [ ] Nova linha em `ml_accounts` (status ativo, `seller_id` da AWA, tokens presentes — não logar tokens)
- [ ] Seletor de conta no topo lista FACILYTY + AWA
- [ ] Trocar para AWA: `/dashboard/items` lista anúncios da AWA (não misturar com FACILYTY)
- [ ] `/dashboard/questions` com filtro da conta AWA
- [ ] Rodar um ciclo de agentes / Pregão com a conta AWA selecionada (ou confirmar que o runtime aceita o `account_id` da AWA)
- [ ] Sentinela / Raio X executáveis para a conta AWA
- [ ] `ML_WRITE_AUTOMATION=false` permanece — nenhuma escrita automática no ML

## Limitações conhecidas (não bloqueiam a conexão)

- Alguns workers/cron ainda leem `PREGAO_ACCOUNT_ID` do `.env` como default do Pregão tick — após conectar a AWA, avaliar se o tick deve rodar para ambas (mudança futura, fora do escopo desta onda).
- Agentes 24/7 no Pregão usam a conta ativa do contexto; validar explicitamente após a conexão.
- Nunca apontar workers de **staging** para a conta de produção FACILYTY (1335).

## Se der erro no OAuth

- "Desculpe, não foi possível conectar" → conferir redirect_uri no app ML e login com o dono da conta AWA
- Callback não chega → DNS/HTTPS e URL exata do callback
- Conta errada conectada → desativar em `ml_accounts.status` e limpar `users.active_ml_account_id` se necessário
