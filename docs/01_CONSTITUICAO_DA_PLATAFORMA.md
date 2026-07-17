# 01 — Constituição da Plataforma

**Versão:** 1.0
**Status:** Aprovado
**Data:** 14/07/2026
**Documento relacionado:** `00_MASTER_PLAN.md`

---

## 1. Finalidade

Esta Constituição estabelece os princípios permanentes de arquitetura, engenharia, dados, inteligência artificial, segurança, testes e governança da plataforma.

Nenhum código, agente, automação, integração ou decisão técnica poderá contrariar este documento sem uma alteração formal, registrada e aprovada.

---

## 2. Princípio soberano

**Conhecimento versionado é soberano.**

A fonte oficial do projeto será o repositório Git. Conversas, prompts, mensagens e decisões informais só passam a ter validade operacional quando forem registradas em documentação versionada.

---

## 3. Ordem obrigatória de trabalho

Toda entrega seguirá esta sequência:

1. necessidade identificada;
2. requisito documentado;
3. análise de impacto;
4. decisão arquitetural;
5. plano de implementação;
6. desenvolvimento;
7. revisão;
8. testes;
9. homologação;
10. aprovação humana;
11. produção;
12. monitoramento;
13. registro do resultado.

Nenhuma etapa crítica poderá ser ignorada.

---

## 4. Responsabilidade humana

A inteligência artificial não possui autoridade final sobre decisões críticas.

Exigem aprovação humana:

- mudanças de arquitetura;
- alterações de preço;
- alterações de orçamento;
- publicação, pausa ou exclusão de anúncios;
- mudanças em estoque;
- ações financeiras;
- permissões;
- exclusão de dados;
- mudanças de segurança;
- automações que afetem clientes ou marketplaces;
- entrada em produção.

Os agentes podem analisar, recomendar, preparar e testar. A decisão final permanece com os responsáveis humanos definidos no projeto.

---

## 5. Princípios de arquitetura

1. A plataforma será modular.
2. Cada sistema terá responsabilidade claramente definida.
3. ERP, eSkill, CRM, Hermes, AI Gateway e banco de dados não serão tratados como uma única aplicação.
4. O PostgreSQL será a base de dados principal.
5. O Supabase poderá ser adotado como serviço gerenciado, sem concentrar toda a lógica de negócio.
6. O eSkill será inicialmente preservado como camada operacional de marketplace.
7. Um ERP será a fonte soberana de estoque.
8. O CRM organizará tarefas, pessoas, prazos, aprovações e histórico.
9. O Hermes orquestrará agentes e fluxos.
10. O AI Gateway desacoplará a plataforma dos fornecedores de modelos.
11. O servidor de contexto entregará aos agentes apenas fontes oficiais e vigentes.
12. Nenhum componente poderá depender exclusivamente da memória de um modelo.

---

## 6. Princípios de reutilização

Antes de desenvolver qualquer módulo, a equipe deverá pesquisar:

- repositórios oficiais;
- projetos open source maduros;
- SDKs;
- APIs;
- frameworks;
- bibliotecas;
- módulos reutilizáveis;
- padrões consolidados;
- documentação atual.

Construção do zero só será autorizada quando:

- não houver solução adequada;
- a solução existente não atender aos requisitos;
- houver risco relevante de segurança ou dependência;
- o custo de adaptação for maior que o de desenvolvimento;
- o diferencial estratégico exigir código próprio.

Toda escolha deverá ser registrada em ADR.

---

## 7. Princípios de dados

1. Dados devem possuir origem identificável.
2. Dados operacionais e históricos não poderão ser sobrescritos sem rastreabilidade.
3. Alterações relevantes deverão gerar auditoria.
4. A plataforma deverá preservar snapshots necessários ao laboratório de inferência.
5. Nenhuma recomendação será considerada confiável sem dados suficientes.
6. Dados incompletos, inconsistentes ou suspeitos deverão ser sinalizados.
7. Multiempresa deverá existir no modelo de dados, não apenas na interface.
8. Cada registro deverá possuir organização, proprietário, data, origem e histórico quando aplicável.
9. Chaves, tokens e segredos nunca serão expostos no frontend.
10. Retenção, backup e recuperação serão definidos antes da produção.

---

## 8. Princípios de inteligência artificial

1. Modelos são componentes substituíveis.
2. Nenhum agente chamará provedores diretamente quando o AI Gateway estiver disponível.
3. Toda chamada relevante deverá registrar modelo, versão, custo, duração, contexto e resultado.
4. Recomendações deverão informar evidência, risco e grau de confiança.
5. Hipóteses não poderão ser apresentadas como fatos.
6. Agentes deverão consultar a documentação oficial antes de agir.
7. O sistema deverá distinguir observação, inferência, recomendação e execução.
8. Nenhum agente poderá alterar regras permanentes por iniciativa própria.
9. A memória do projeto ficará em dados e documentos, não apenas em conversas.
10. Decisões de alto impacto deverão usar validação humana e, quando necessário, mais de um mecanismo de revisão.

---

## 9. Governança dos agentes

Os agentes serão divididos em grupos:

### Engenharia

- arquiteto;
- requisitos;
- backend;
- frontend;
- dados;
- integração;
- segurança;
- DevOps;
- revisão;
- testes;
- documentação;
- qualidade;
- observabilidade.

### Negócio

- SEO;
- concorrência;
- conteúdo;
- imagens;
- atributos;
- conversão;
- preço;
- margem;
- publicidade;
- estoque;
- atendimento;
- reputação;
- oportunidades;
- experimentos;
- estratégia;
- memória;
- qualidade de dados.

O Arquiteto de Software coordena decisões técnicas. O Agente Estratégico coordena prioridades de negócio. Ambos submetem decisões críticas à aprovação humana.

---

## 10. Testes como pilar de arquitetura

Nenhuma entrega será considerada concluída sem testes compatíveis com seu risco.

A estratégia deverá incluir:

- testes unitários;
- testes de integração;
- testes de contrato;
- testes de segurança;
- testes de regressão;
- testes end-to-end;
- testes de carga quando necessários;
- testes de recuperação;
- testes de permissões;
- testes de falha de integrações.

O Playwright será o executor inicial de testes end-to-end.

O GitHub Actions será o executor inicial de integração contínua.

A VPS de produção não será usada como ambiente principal de testes.

---

## 11. Regras de Git e entrega

1. A branch principal será protegida.
2. Nenhum agente gravará diretamente na branch principal.
3. Toda mudança relevante será feita em branch própria.
4. Pull request será obrigatório.
5. Revisão e testes serão obrigatórios.
6. Falha de teste bloqueará integração.
7. Mudanças de arquitetura exigirão ADR.
8. Segredos não poderão entrar no repositório.
9. Versões de produção serão identificadas.
10. Rollback deverá existir para mudanças relevantes.

---

## 12. Segurança

1. Menor privilégio será o padrão.
2. Acesso será concedido por função.
3. Dados de empresas diferentes serão isolados.
4. Tokens do Mercado Livre e de outros serviços permanecerão no backend.
5. Logs não deverão expor segredos ou dados sensíveis.
6. Toda ação crítica deverá ser auditável.
7. Dependências serão verificadas.
8. Atualizações de segurança terão prioridade.
9. Produção, homologação e desenvolvimento serão separados.
10. Incidentes terão processo de resposta e registro.

---

## 13. Laboratório de inferência

O laboratório de inferência deverá:

- observar;
- registrar;
- comparar;
- formular hipóteses;
- controlar variáveis;
- executar experimentos;
- medir;
- concluir;
- armazenar aprendizado;
- atualizar o grau de confiança.

O laboratório não será usado para contornar proteções, ocultar automações ou acessar mecanismos internos não autorizados.

Seu propósito será aprender com dados permitidos, observação legítima e testes controlados.

---

## 14. Critérios para automação

Uma ação só poderá ser automatizada quando:

- houver regra clara;
- a fonte de dados for confiável;
- o comportamento tiver sido testado;
- o risco for conhecido;
- existir log;
- existir limite;
- existir tratamento de falha;
- existir rollback quando aplicável;
- houver aprovação formal.

A automação deverá começar em modo de observação e recomendação antes de executar ações reais.

---

## 15. Documentação obrigatória

O repositório deverá manter:

- Master Plan;
- Constituição;
- glossário;
- arquitetura;
- roadmap;
- PRDs;
- ADRs;
- contratos de API;
- modelo de dados;
- regras de negócio;
- estratégia de testes;
- estratégia de segurança;
- runbooks;
- registro de incidentes;
- histórico de experimentos;
- changelog;
- manual operacional.

---

## 16. Controle de mudanças

Qualquer mudança nesta Constituição deverá conter:

- motivo;
- impacto;
- alternativa considerada;
- riscos;
- responsável;
- data;
- aprovação;
- versão anterior;
- nova versão.

Alterações sem registro não terão validade oficial.

---

## 17. Critério de aprovação

Este documento será considerado aprovado quando:

- direção do projeto revisar;
- pontos de discordância forem resolvidos;
- responsáveis forem definidos;
- a versão for atualizada para 1.0;
- o commit de aprovação for criado no repositório oficial.

---

## 18. Próxima ação

Após a revisão desta Constituição, deverá ser criado o documento:

`02_GLOSSARIO.md`

Em paralelo, deverá ser aberto o primeiro registro de decisão:

`ADR/ADR-001-CONHECIMENTO-VERSIONADO.md`

---

## 19. Registro de aprovação

**Decisão:** Aprovado
**Data:** 15/07/2026
**Efeito:** Este documento passa a vigorar como norma oficial da plataforma a partir da versão 1.0.
**Próxima revisão:** Somente por alteração formal, com justificativa, impacto, responsável e novo versionamento.
