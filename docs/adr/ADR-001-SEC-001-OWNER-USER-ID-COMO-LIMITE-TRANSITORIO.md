# ADR-001 — owner_user_id como limite transitorio de autorizacao (SEC-001)

**Status:** Proposto (aguardando aprovacao humana)
**Data:** 16/07/2026
**Relacionado a:** `docs/security/SEC-001-ISOLAMENTO-CONTAS-MERCADO-LIVRE.md`, `docs/01_CONSTITUICAO_DA_PLATAFORMA.md`
**Autor:** Investigacao automatizada (Fase -1, SEC-001)

---

## Contexto

O documento `SEC-001-ISOLAMENTO-CONTAS-MERCADO-LIVRE.md` e o pedido de correcao original
exigem que o `AccountAccessPolicy` valide `organization_id` em toda autorizacao de conta,
como camada adicional ao `user_id`.

A inspecao do schema atual (`database/ci/schema.sql`) confirma que:

- a tabela `users` nao possui coluna `organization_id`;
- a tabela `ml_accounts` referencia apenas `user_id` (FK direta para `users.id`);
- nao existe nenhuma tabela `organizations`, `organization_members` ou equivalente
  (`grep -ic "organization" database/ci/schema.sql` = 0).

O proprio `docs/audits/AUDITORIA_TECNICA_ESKILL_ZIP_V1.md` (secao 5) ja reconhece essa
lacuna: multi-conta ainda nao e multiempresa - nao ha organizacoes, membros, papeis
por organizacao nem contas de marketplace vinculadas a uma organizacao.

## Decisao

Para a correcao SEC-001, `organization_id` sera tratado como **igual a `owner_user_id`**
(o dono direto da conta em `ml_accounts.user_id`). Ou seja, cada usuario e, na pratica,
sua propria organizacao de um unico membro.

`AuthorizedAccountContext::organizationId` sera preenchido com o `owner_user_id` da conta,
e a politica de autorizacao compara `actor->organizationId === context->organizationId`
- hoje equivalente a comparar `user_id`, mas mantendo o contrato ja pronto para quando um
modelo real de organizacoes existir (bastara trocar a fonte do dado, nao a logica de
autorizacao).

**Nenhuma migration de organizacoes sera feita nesta correcao.**

## Impacto sobre o isolamento Facility / Falcao

Como cada conta Mercado Livre pertence a exatamente um usuario (`ml_accounts.user_id`), e
Facility e Falcao sao usuarios (ou conjuntos de usuarios) distintos no sistema atual, o
isolamento exigido pelo SEC-001 (usuario de uma empresa nunca acessar conta da outra) e
**totalmente garantido pela comparacao de `user_id`**, independentemente de existir ou nao
um conceito formal de organizacao. Este ADR nao enfraquece o criterio de fechamento do
SEC-001.

## Alternativas consideradas

1. **Criar tabelas `organizations`/`organization_members` agora** - rejeitada nesta
   correcao: expande o escopo do SEC-001 para uma mudanca de modelo de dados maior,
   contrariando a recomendacao da propria auditoria tecnica de nao misturar a correcao de
   isolamento com a introducao de multiempresa. Fica registrado como trabalho futuro.
2. **Omitir `organization_id` do contrato inteiramente** - rejeitada: o contrato
   `AccountAccessPolicy`/`AuthorizedAccountContext` exigido pelo SEC-001 ja preve o campo;
   omiti-lo exigiria retrabalho quando organizations for implementado. Preencher com
   `owner_user_id` mantem o contrato estavel.

## Riscos

- Se, no futuro, uma organizacao passar a ter multiplos usuarios com acesso a mesma conta
  ML, a comparacao `organization_id == owner_user_id` deixara de refletir a realidade e
  precisara ser migrada para um modelo real de organizacoes. Este ADR deve ser revisado
  nesse momento.
- Nenhum risco de seguranca adicional identificado: o isolamento efetivo continua sendo
  `user_id`, que e a unica fonte de verdade de propriedade hoje.

## Controle de mudancas

- **Motivo:** ausencia de modelo de organizacoes no schema atual, mas exigencia do SEC-001.
- **Responsavel:** a definir por aprovacao humana.
- **Versao anterior:** N/A (primeiro ADR do repositorio).
- **Nova versao:** 1.0 - proposto, pendente de aprovacao.
