# ADR-003 — Foco operacional em conversão e irregularidades Mercado Livre

**Status:** Proposto  
**Data:** 17/07/2026  
**Decisores:** Direção do projeto, Produto, Arquitetura, Operação Marketplace  
**Contexto piloto:** Facility · MLB  
**Relacionados:** ADR-001 (SEC-001) · PLAN_MOTOR_IRREGULARIDADES_CONVERSAO_V1 · inventário eSkill · PRD Concept Engine (branch documental separada)

---

## Contexto

O monólito eSkill acumulou dezenas de domínios (clone, ads automático, canais laterais, agentes) que **não aumentam conversão** do anúncio Facility e elevam risco operacional (pausas por preço, moderação, gasto Ads em listing doente).

A documentação oficial do Mercado Livre (via MCP) deixa claro que:

1. Irregularidades se consultam por **moderations** (`last_moderation`, `infractions`) e status/tags de item.  
2. Alavancagem de exposição se mede por **`/item/{id}/performance`** (não mais `/health`).  
3. Reputação da conta (claims, cancelamentos, atraso) afeta o termômetro e confiança.  
4. Autopeças dependem de **compatibilidades**.

O código atual já cobre performance de forma parcial, mas **não chama** as APIs de moderação — gap crítico para detectar o que trava vendas.

---

## Decisão

Adotar, a partir desta ADR, o **norte operacional** do eSkill Marketplace Core:

> Detectar irregularidades oficiais do ML e priorizar ações que recuperam ou aumentam vendas; congelar automações que não passam por evidência ML + aprovação humana.

### Implicações

1. **Prioridade P0:** motor read-only de moderations + fila de bloqueios.  
2. **Prioridade P0/P1:** fila de performance (WARNING → OPPORTUNITY) e compatibilidades Facility.  
3. **Quarentena explícita:** Clone automático, pricing automático, SEO em massa, Ads automático, agentes autônomos — sem novas features; workers desligados por padrão.  
4. **Arquivar (sem expandir):** Shopee, EAN, WhatsApp/Telegram, Brevo, OpenClaw, AWA seller scanning.  
5. **Escrita no ML** (reativar item, patch de atributo/foto/preço) só após:
   - SEC-001 mergeado e wired;
   - UI/fluxo de aprovação humana;
   - auditoria.
6. **Concept Engine** continua responsável por investigar/aprender; este ADR não o implementa, mas define os **sinais** que o eSkill deve emitir.

---

## Alternativas consideradas

| Opção | Descrição | Motivo de rejeição / adiamento |
|-------|-----------|--------------------------------|
| A — Continuar expandindo SEO Killer / Clone | Mais automação de conteúdo | Não fecha gap de moderação; aumenta risco DESC/PQT |
| B — Apagar módulos agora | Delete em massa | Prematuro sem inventário de workers/cron e sem PRs de higiene |
| C — Só Concept Engine primeiro | Inteligência antes do sensor | Concept Engine sem sinais de moderação fica cego ao bloqueio real |
| D — **Foco irregularidades + performance (escolhida)** | Sensor oficial ML primeiro | Alinha Constituição (eSkill coleta/executa) e ROI Facility |

---

## Consequências

### Positivas
- Fila operacional acionável com reason/remedy oficiais.  
- Redução de ruído de produto.  
- Melhor handoff para Concept Engine.  
- Menor risco de pausa por automação.

### Negativas / custos
- Demanda implementação controlada (client + sync + UI).  
- Rate limits ML exigem cache e priorização.  
- Módulos em quarentena podem gerar pressão política interna (“já temos clone”).

### Neutras
- Código legado permanece no repo até PRs de higiene.  
- PRD Concept Engine segue em branch documental própria.

---

## Critérios de aceite desta decisão

- [ ] Direção aprova status **Aceito**.  
- [ ] Plano PLAN-IRR-001 revisado.  
- [ ] SEC-001 permanece pré-condição para escrita e multi-conta.  
- [ ] Nenhum worker de quarentena reabilitado sem nova ADR.

---

## Notas

- Este ADR **não** substitui ADR-002 (fronteira Concept Engine), ainda a ser proposto na linha do PRD.  
- Nenhuma alteração de código acompanha este documento.
