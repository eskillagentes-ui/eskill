# 00 — Master Plan da Plataforma

**Versão:** 0.1
**Status:** Em revisão
**Data:** 14/07/2026
**Fonte soberana:** documentação versionada no repositório Git

---

## 1. Propósito

Construir uma plataforma própria de inteligência operacional e comercial para e-commerce e marketplaces, inicialmente voltada à operação da Facility, com capacidade futura para múltiplas lojas, múltiplas contas e múltiplos agentes.

A plataforma não será apenas um CRM. Ela deverá observar, comparar, aprender, formular hipóteses, executar experimentos controlados, medir resultados, priorizar ações e registrar o conhecimento obtido.

O objetivo é transformar dados operacionais em decisões repetíveis, auditáveis e orientadas a lucro.

## 2. Visão de longo prazo

Criar um centro de inteligência de marketplace que opere continuamente, conecte dados do Mercado Livre, ERP, eSkill, anúncios, vendas, estoque, atendimento, publicidade e concorrência, e produza recomendações e tarefas priorizadas.

A evolução prevista é:

1. observação;
2. análise;
3. recomendação;
4. criação de tarefas;
5. execução assistida;
6. automação controlada.

Nenhuma automação crítica será liberada sem histórico, regras, testes e aprovação humana.

## 3. Primeiro escopo de negócio

A primeira operação será a **Facility**.

O primeiro foco funcional será:

- inteligência de SEO para Mercado Livre;
- análise de anúncios próprios;
- comparação com anúncios concorrentes;
- laboratório de inferência;
- registro de alterações;
- acompanhamento de posição e desempenho;
- criação de hipóteses;
- execução de experimentos;
- medição de impacto em visitas, conversão, vendas, margem e lucro.

O primeiro produto funcional não será um gerador de anúncios. Será uma base de inteligência capaz de aprender o que funciona.

## 4. Princípios permanentes

1. Conhecimento versionado é soberano.
2. Documentação vem antes da implementação.
3. Arquitetura vem antes do código.
4. Testes são pilar de arquitetura.
5. Nenhuma alteração crítica entra em produção sem revisão.
6. Toda decisão deve ser rastreável.
7. Toda recomendação deve informar o motivo e a evidência.
8. Toda hipótese deve possuir um plano de teste.
9. Todo experimento deve registrar resultado, período e condições.
10. Toda automação crítica deve ser auditável e reversível.
11. Modelos de IA são substituíveis.
12. O conhecimento pertence à empresa, não ao fornecedor de IA.
13. O humano mantém a decisão final nas ações críticas.
14. ERP, CRM, eSkill, Hermes e integrações têm responsabilidades separadas.
15. Dados ruins não podem alimentar decisões sem validação.

## 5. Arquitetura conceitual inicial

### ERP

Fonte principal de estoque, produtos, custos e movimentações físicas.

### eSkill

Camada operacional já integrada ao Mercado Livre, mantida inicialmente como sistema de execução de marketplace.

### Plataforma própria

Centro de inteligência, experimentação, análise, governança e priorização.

### CRM operacional

Organiza tarefas, responsáveis, histórico, prazos, aprovações e execução humana.

### Hermes

Orquestrador dos agentes e supervisor dos fluxos de inteligência.

### AI Gateway

Camada técnica responsável por distribuir tarefas entre diferentes modelos de IA, controlar custos, registrar chamadas e permitir troca de provedores.

### Servidor de contexto

Serviço que fornece aos agentes apenas a documentação, decisões e regras relevantes para cada tarefa.

### Banco de dados

PostgreSQL gerenciado, com Supabase como candidato inicial.

### Infraestrutura

Aplicação web e serviços de backend em VPS ou nuvem. A produção não dependerá de computador local ligado.

### Repositório

GitHub como fonte oficial de código, documentação, decisões, testes e histórico.

## 6. Responsabilidades por sistema

### ERP

- estoque;
- custo;
- entrada e saída;
- SKU;
- saldo físico;
- reserva;
- movimentação entre canais;
- sincronização de estoque.

### eSkill

- integração operacional atual com o Mercado Livre;
- execução de rotinas já disponíveis;
- fornecimento de dados por integração, API ou exportação.

### CRM

- tarefas;
- responsáveis;
- prazos;
- aprovações;
- histórico;
- status;
- prioridades;
- evidências;
- acompanhamento do trabalho.

### Plataforma de inteligência

- comparação;
- inferência;
- análise;
- experimentos;
- priorização;
- pontuação;
- memória histórica;
- geração de recomendações.

### Hermes

- orquestração;
- distribuição de tarefas;
- coordenação de agentes;
- consolidação de análises;
- criação de planos;
- aplicação das regras de governança.

## 7. Grupos de agentes

### 7.1 Agentes de engenharia

- Arquiteto de software;
- Engenheiro de requisitos;
- Desenvolvedor backend;
- Desenvolvedor frontend;
- Engenheiro de dados;
- Engenheiro de integração;
- Engenheiro de segurança;
- Revisor de código;
- Engenheiro de testes;
- Agente de documentação;
- Agente de qualidade;
- Agente de observabilidade;
- Agente de DevOps.

O **Arquiteto de Software** é o principal agente de decisão técnica. Nenhuma decisão estrutural será implementada sem sua análise e sem aprovação humana.

### 7.2 Agentes de negócio

- Investigador de mercado;
- Investigador de concorrentes;
- Agente de SEO;
- Agente de atributos e ficha técnica;
- Agente de conteúdo;
- Agente de imagens;
- Agente de conversão;
- Agente de precificação;
- Agente de margem;
- Agente de Mercado Ads;
- Agente de estoque;
- Agente de pedidos;
- Agente de atendimento;
- Agente de reputação;
- Agente de oportunidades;
- Agente de novos produtos;
- Agente de experimentos;
- Agente de qualidade de dados;
- Agente estratégico;
- Agente de memória.

### 7.3 Motor de decisão

O motor de decisão recebe sinais dos agentes e produz:

- prioridade;
- evidência;
- nível de confiança;
- impacto estimado;
- risco;
- ação recomendada;
- responsável;
- prazo;
- necessidade de aprovação;
- resultado posterior.

## 8. Laboratório de inferência

O laboratório de inferência será a primeira entrega funcional de inteligência.

Ele deverá registrar:

- anúncio próprio;
- anúncio concorrente;
- seller;
- MLB;
- categoria;
- título;
- atributos;
- preço;
- promoção;
- frete;
- prazo;
- reputação;
- imagens;
- visitas;
- perguntas;
- vendas;
- conversão;
- posição observada;
- alterações feitas;
- data e hora;
- resultado posterior.

O laboratório não tentará acessar código interno ou contornar mecanismos da plataforma. Seu objetivo será inferir padrões por observação, comparação e experimentação controlada.

### Ciclo

1. observar;
2. registrar;
3. comparar;
4. formular hipótese;
5. definir métrica;
6. executar teste;
7. medir;
8. concluir;
9. armazenar aprendizado;
10. atualizar regras e confiança.

## 9. Metodologia de experimentos

Todo experimento deverá conter:

- identificador;
- hipótese;
- anúncio ou grupo analisado;
- grupo de controle, quando aplicável;
- variável alterada;
- variáveis mantidas;
- data de início;
- duração mínima;
- métrica principal;
- métricas secundárias;
- critério de sucesso;
- resultado;
- conclusão;
- grau de confiança;
- decisão posterior.

Não será permitido alterar simultaneamente várias variáveis quando isso impedir a atribuição do resultado.

## 10. Dados e integrações iniciais

Fontes previstas:

- API oficial do Mercado Livre;
- eSkill;
- ERP;
- banco PostgreSQL;
- arquivos de importação;
- dados internos de custo e margem;
- Mercado Ads, quando disponível;
- histórico de experimentos;
- dados públicos permitidos;
- registros de tarefas e aprovações.

Toda coleta deve respeitar permissões, limites técnicos e regras da plataforma.

## 11. Infraestrutura e desenvolvimento

### Estrutura inicial do repositório

```text
/
├── apps/
├── services/
├── agents/
├── packages/
├── docs/
├── tests/
├── infra/
├── scripts/
└── .github/
```

### Ambientes

- desenvolvimento local;
- ambiente de testes;
- homologação;
- produção.

### Git

- branch principal protegida;
- desenvolvimento por branches;
- pull request obrigatório;
- revisão obrigatória;
- testes obrigatórios;
- histórico de decisões;
- tags de versão.

### Testes

- testes unitários;
- testes de integração;
- testes de contrato de API;
- testes de segurança;
- testes de regressão;
- testes end-to-end com Playwright;
- testes de carga quando necessário.

O Playwright deverá rodar prioritariamente no GitHub Actions, evitando sobrecarregar a VPS de produção.

## 12. Servidor de contexto

A documentação oficial ficará no GitHub.

Um serviço de contexto deverá:

- indexar documentos aprovados;
- recuperar trechos relevantes;
- informar versão e status;
- impedir uso de documentos obsoletos;
- fornecer contexto aos agentes;
- registrar qual fonte sustentou cada decisão.

Os agentes não poderão atuar apenas com base no prompt momentâneo. Antes de executar tarefas, deverão consultar a documentação aplicável.

## 13. Governança de IA

Cada tarefa de IA deverá registrar:

- agente;
- modelo utilizado;
- versão do modelo;
- contexto consultado;
- instrução recebida;
- resposta;
- custo;
- tempo;
- decisão tomada;
- aprovação humana, quando necessária.

O AI Gateway deverá permitir:

- múltiplos provedores;
- roteamento por custo e capacidade;
- fallback;
- limites;
- auditoria;
- cache;
- controle de acesso;
- troca de modelos sem reescrever o sistema.

## 14. Roadmap inicial

### Fase 0 — Fundação documental

- Master Plan;
- Constituição da Plataforma;
- glossário;
- registro de premissas;
- mapa de arquitetura;
- roadmap;
- ADRs;
- padrões de testes;
- padrões de segurança;
- governança de agentes.

### Fase 1 — Descoberta técnica

- mapear eSkill;
- mapear integrações existentes;
- mapear ERP;
- validar API do Mercado Livre;
- identificar dados disponíveis;
- definir lacunas;
- criar protótipo de ingestão de dados.

### Fase 2 — Base de dados

- modelo de dados;
- PostgreSQL/Supabase;
- autenticação;
- multiempresa;
- permissões;
- auditoria;
- histórico temporal.

### Fase 3 — Laboratório de inferência

- coleta;
- snapshots;
- comparação;
- registro de mudanças;
- experimentos;
- resultados;
- painel inicial.

### Fase 4 — Agentes especialistas

- SEO;
- concorrência;
- conteúdo;
- conversão;
- preço;
- margem;
- Ads;
- qualidade de dados.

### Fase 5 — Motor de decisão

- consolidação de sinais;
- pontuação;
- confiança;
- impacto;
- prioridade;
- criação automática de tarefas.

### Fase 6 — Execução assistida

- aprovações;
- alterações reversíveis;
- automações de baixo risco;
- monitoramento;
- rollback.

### Fase 7 — Escala multiempresa

- múltiplas lojas;
- isolamento de dados;
- múltiplas contas;
- filas;
- observabilidade;
- otimização de custo.

## 15. Critérios de sucesso da primeira etapa

A Fase 0 será considerada concluída quando houver:

- Master Plan aprovado;
- Constituição aprovada;
- glossário aprovado;
- arquitetura aprovada;
- lista de agentes aprovada;
- ADRs iniciais;
- estratégia de testes;
- estratégia de segurança;
- repositório criado;
- fluxo de aprovação definido.

## 16. Decisões já aprovadas

- O projeto será guiado por documentação versionada.
- GitHub será a fonte oficial de conhecimento e código.
- O sistema será web.
- A produção deverá rodar em VPS ou nuvem.
- PostgreSQL será o banco principal.
- Supabase é o candidato inicial para banco gerenciado, autenticação e storage.
- O eSkill será inicialmente preservado como camada operacional.
- Um ERP será o dono do estoque.
- O CRM organizará o trabalho.
- O Hermes orquestrará os agentes.
- Haverá um AI Gateway independente.
- Haverá um servidor de contexto.
- Testes serão executados antes de produção.
- Playwright será usado nos testes end-to-end.
- GitHub Actions será o executor inicial de testes automatizados.
- O laboratório de inferência será uma das primeiras entregas funcionais.
- A Facility será a primeira operação.
- SEO inteligente será o primeiro domínio de negócio.

## 17. Questões em aberto

1. Qual ERP será adotado: Bling, Tiny ou outro?
2. Qual versão e quais APIs do eSkill estão disponíveis?
3. Quais dados o eSkill permite extrair?
4. Quais contas do Mercado Livre participarão do piloto?
5. Qual será o primeiro conjunto de produtos?
6. Qual será a infraestrutura inicial?
7. Qual será o orçamento mensal de modelos e infraestrutura?
8. Quais modelos serão usados em cada classe de tarefa?
9. Quais ações poderão ser automáticas?
10. Quem terá poder de aprovação em cada domínio?

## 18. Próximos documentos

1. `01_CONSTITUICAO_DA_PLATAFORMA.md`
2. `02_GLOSSARIO.md`
3. `03_ARQUITETURA_GERAL.md`
4. `04_ROADMAP.md`
5. `05_GOVERNANCA_DE_AGENTES.md`
6. `06_ESTRATEGIA_DE_TESTES.md`
7. `07_SEGURANCA_E_COMPLIANCE.md`
8. `08_LABORATORIO_DE_INFERENCIA_PRD.md`
9. `ADR/ADR-001-CONHECIMENTO-VERSIONADO.md`
10. `ADR/ADR-002-ARQUITETURA-MULTIMODELO.md`

## 19. Próxima ação imediata

Revisar este documento linha por linha, registrar ajustes e aprovar a versão 1.0.

Após a aprovação, criar a Constituição da Plataforma e o primeiro ADR.
