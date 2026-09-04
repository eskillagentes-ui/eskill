# Fluxo de Caixa MP com horizonte temporal

Status: GO do dono em 2026-09-04, após revisão do mock `/_preview/fluxo-caixa-mock.html`.

## Objetivo

Substituir somente o conteúdo da aba Fluxo de Caixa em Relatórios Financeiros por uma linha do tempo da carteira Mercado Pago. Manter cards do Caixa MP e todas as outras abas. Entregar Planilha e Gráfico, horizonte futuro configurável e detalhe auditável ao clicar nos valores.

## Fontes e regras financeiras

- Saldo de hoje: cascata já existente de `getAccountBalance()`.
- Realizado: `financial_ledger_entries` da conta ativa, usando apenas movimentos de caixa confirmados.
- A liberar: `settlement_release` pendente com `available_at`/`money_release_date` futura.
- Dívida: períodos oficiais de billing ML e MP com `unpaid_amount > 0`, agrupados por vencimento.
- Liberado e a liberar são mutuamente exclusivos por `payment_id`; o registro posted vence snapshots pending.
- Ads/frete/reclamações só entram separadamente quando houver evidência explícita de efeito de caixa. Não duplicar valor já líquido ou faturado.
- Saídas futuras sem agenda oficial são `null`/N/D, não zero.
- Cálculos monetários em centavos inteiros e timezone `America/Sao_Paulo`.
- O fechamento de cada faixa é a abertura da próxima. O saldo observado ancora a faixa até hoje; o futuro é identificado como “somente compromissos conhecidos”.

## Contrato e interface

- Adaptar `GET /api/financials/cashflow`, mantendo chaves legadas temporárias.
- Resposta principal: `as_of`, `currency`, `account_id`, `summary`, `buckets`, `warnings`, `freshness` e detalhes por célula.
- Colunas: faixa, saldo anterior, liberações, a liberar, saques/Pagar, bloqueios, dívida ML/MP, Ads, frete/reclamações e saldo atual.
- Linha de saldo com zero destacado; ponto/trecho negativo em vermelho.
- Estados de loading, erro parcial e N/D explicados ao usuário.

## Segurança e isolamento

- Toda consulta deve usar `account_id` da sessão/serviço; nenhum somatório entre contas.
- Nunca expor tokens ou payloads sensíveis no frontend/logs.
- Somente APIs oficiais e operações GET nesta capability.
- Não executar workers/tick do staging contra FACILYTY 1335/1337.
- Staging isolado pode ser usado para smoke visual. Produção somente após aprovação explícita e smoke verde.

## Aceite

1. Fórmula e encadeamento testados em centavos.
2. Pagamento liberado não aparece também como previsto.
3. N/D continua diferente de zero.
4. Planilha e gráfico derivam dos mesmos buckets.
5. Clique em valor mostra fonte, referência, status, data e indicação de projeção.
6. PHP lint, testes focados e smoke do staging passam.
7. Nenhum deploy em produção faz parte deste GO.
