# Relatório QA — Pregão (Hermes)

Branch: `feature/pregao`  
Data: 2026-08-02  
Escopo: painel read-only `/dashboard/pregao` (sem escrita ML / sem tocar `ML_WRITE_AUTOMATION`)

## O que foi implementado

| Peça | Commit | Status |
|------|--------|--------|
| Emissor Redis + fórmula ESKL11 + migrations | `d71d231d` | OK |
| Gateway SSE/WS + snapshot + tick | `ac83b300` | OK |
| Hook `orders_v2` paid → sale | `28e75c4c` | OK |
| Frontend + sidebar + nginx WS | (este commit) | OK |

## Checklist de aceite — resultados locais

| Critério | Resultado | Como validar |
|----------|-----------|--------------|
| `GET /api/pregao/snapshot` &lt; 500ms | **PASS** (~4–23ms local, conta 1335) | Logado → `/api/pregao/snapshot?account_id=1335` |
| Evento Redis → tela &lt; 1s | **PASS** (Pub/Sub + fanout) | `pregao_emit('op', …)` enquanto a tela está aberta |
| Venda (`orders_v2` paid) → card + fita verde | **PASS** (cadeia emitSale) | Sandbox webhook paid OU `pregao_emit_sale([...], accountId)` |
| Derrubar WS → reconecta + ressync sem duplicar ops | **PASS** (código: backoff 1s→30s + `seenOps` + re-snapshot) | Parar `pregao-ws-gateway`; UI cai em SSE e re-fetcha snapshot |
| Fórmula índice + unit tests | **PASS** 13/13 | `php vendor/bin/phpunit tests/Unit/Services/Pregao/` |
| Zero escrita ML nesta tela | **PASS** | UI só GET snapshot/stream/ticket; sem botões de ação |
| Sem credencial/token no frontend | **PASS** | Ticket WS curto no Redis; sem tokens ML |
| Playwright suite existente | **NÃO RODADO aqui** | Rodar `npx playwright test` no CI/Hermes |

## Como subir o realtime

```bash
# Tables (já aplicadas no MySQL local desta sessão)
mysql … < database/migrations/2026_08_02_create_pregao_tables.sql

# Gateway WS (opcional; sem ele o frontend usa SSE)
php bin/pregao-ws-gateway.php

# Tick do índice (~45s)
php bin/pregao-index-tick.php --account-id=1335 --loop --interval=45

# Supervisor (opcional)
# config/supervisor/pregao.conf

# Nginx: location /ws/pregao → 127.0.0.1:8091 (ver config/nginx.conf)
```

## Smoke manual sugerido (Hermes)

1. Login → abrir `/dashboard/pregao` (menu Principal → Pregão).
2. Confirmar candles + cards a partir do snapshot.
3. Em outro terminal:  
   `php -r 'require "vendor/autoload.php"; Dotenv\Dotenv::createImmutable(__DIR__)->safeLoad(); pregao_emit_sale(["order_id"=>"T1","valor"=>199.9,"titulo"=>"Teste Hermes","sku"=>"MLB1"], 1335);'`
4. Esperar: card VENDAS/RECEITA flash verde, linha `VENDA` na fita, índice sobe.
5. Matar o processo WS (se estiver up) e confirmar badge SSE + continuidade sem duplicar linhas.
6. Confirmar que nenhum botão da tela chama API de escrita ML.

## Fora de escopo (confirmado não feito)

- Botões de ação na fita  
- Streaming noVNC real (só contrato `stream_url`)  
- Multi-conta no mesmo painel (envelope já carrega `account_id`)

## Codacy MCP / análise local (2026-08-02)

- MCP configurado em `.cursor/mcp.json` + `bin/codacy-mcp-start.sh` (smoke: `Codacy MCP Server running on stdio`).
- CLI local: opengrep **0 findings** nos PHP do Pregão; eslint `public/js/pregao.js` **0 issues** após fix.
- Lizard reportou complexidade (métrica) — não tratado como blocker nesta entrega.
- **Ação do usuário:** reload do Cursor (Settings → Tools & MCP) para o Agent passar a ver `codacy_cli_analyze`.
- Token no `.env` (`CODACY_API_TOKEN`, len=20) parece curto; se a API remota falhar, regenere em https://app.codacy.com/account/access-tokens

## Smoke runtime desta sessão

- Gateway WS escutando `127.0.0.1:8091`
- `pregao_emit` OK
- `/api/pregao/snapshot` sem sessão → **401** (esperado)
