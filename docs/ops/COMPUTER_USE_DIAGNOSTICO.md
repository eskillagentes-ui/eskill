# Diagnóstico — Computer Use / captura de tela no Cursor

Data: 2026-08-03

## Sintoma

Sessão MCP de browser/Computer Use não entrega ferramentas utilizáveis para
captura de tela com cursor visível. Neste ambiente, o servidor
`cursor-ide-browser` aparece como `serverStatus: ready`, porém o catálogo de
ferramentas retornou **lista vazia** (`tools: []`). Sem tools, não há
`browser_take_screenshot`, `browser_snapshot` nem interação segura.

## Causa provável

1. **Sessão / autorização da conversa**: Computer Use e o browser MCP exigem
   autorização do usuário na conversa (ou feature flag da org/conta). Uma
   sessão expirada ou uma conversa iniciada sem o grant não publica as tools,
   mesmo com status “ready”.
2. **Contexto SSH remoto**: o agente roda via SSH no host
   `/home/eskill/htdocs/eskill.com.br`. O browser embutido do Cursor Desktop é
   local à máquina do usuário; sem bridge/autorização ativa, o MCP não expõe
   as ferramentas de captura.
3. **Não é falha do código do Eskill** — é infraestrutura do cliente Cursor/MCP.

## O que o dono precisa fazer

1. Abrir (ou reiniciar) uma conversa no **Cursor Desktop** (não só SSH puro)
   com Computer Use / Browser MCP habilitado.
2. Aceitar o prompt de **autorização** do MCP `cursor-ide-browser` quando
   aparecer (Settings → MCP → reset/reconnect se necessário).
3. Confirmar que as tools `browser_tabs`, `browser_navigate`,
   `browser_take_screenshot` listam no painel MCP.
4. Só então pedir captura com cursor visível nesta branch.

## Política deste agente

Enquanto as tools não estiverem disponíveis: **não simular clique** e não
fingir captura. Transparência: reportar bloqueio e seguir só com evidência
de código/testes.
