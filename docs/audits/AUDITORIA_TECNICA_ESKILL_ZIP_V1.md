# Auditoria Técnica do eSkill — ZIP v1

**Versão:** 1.0
**Status:** Concluída — análise estática inicial
**Data:** 16/07/2026
**Fonte analisada:** `eskill-master.zip`
**Escopo:** código-fonte, configuração, documentação, testes, migrations, rotas, segurança, IA e infraestrutura.
**Limite desta etapa:** o sistema não foi conectado a contas reais nem executado integralmente com todas as dependências.

---

## 1. Parecer executivo

O eSkill **deve ser aproveitado**, principalmente pela integração já desenvolvida com o Mercado Livre. Não compensa reconstruir OAuth, renovação de tokens, sincronização, anúncios, pedidos, perguntas, webhooks e parte dos workers sem antes validar o que já funciona.

Ao mesmo tempo, o eSkill **não deve receber agora o CRM, o Hermes, o laboratório de inferência e todos os novos agentes dentro do mesmo monólito**. O código já é grande, possui módulos sobrepostos e contém riscos que precisam ser corrigidos antes da expansão.

A arquitetura recomendada permanece:

```text
Mercado Livre
      ↓
eSkill — núcleo operacional do marketplace
      ↓ API interna versionada + eventos
Nova plataforma de inteligência
      ↓
CRM operacional
      ↓
Hermes e agentes
      ↓
AI Gateway consolidado
```

O ERP continuará como fonte soberana de estoque e custos.

---

## 2. Dimensão real do projeto

A inspeção encontrou aproximadamente:

- 13.968 arquivos no ZIP;
- 1.223 arquivos PHP;
- 525 mil linhas de PHP;
- 135 controllers;
- 371 services;
- 7 models;
- 305 arquivos de teste PHPUnit;
- 123 migrations;
- 271 arquivos de documentação;
- 1.712 registros de rota identificados por análise estática;
- 27 rotas duplicadas.

O sistema não é um protótipo pequeno. Há bastante trabalho acumulado, mas também há sinais claros de crescimento sem uma arquitetura suficientemente controlada.

---

## 3. Tecnologias identificadas

### Aplicação principal

- PHP 8.4;
- arquitetura própria com Router, Middleware, Controllers e Services;
- MySQL com PDO;
- Redis;
- Guzzle;
- Monolog;
- Docker;
- PHPUnit;
- Playwright;
- GitHub Actions.

### Serviços complementares

- FastAPI/Python para NLP;
- workers e jobs em segundo plano;
- monitoramento de contas;
- Cloudflare Worker;
- módulos internos de inteligência artificial;
- prompts e estruturas para agentes de desenvolvimento.

---

## 4. Descoberta crítica: isolamento de contas

### Classificação

**Risco provável: crítico**
**Tipo:** IDOR / acesso cruzado entre contas
**Status:** precisa de correção antes de operação multiempresa.

### Evidência técnica

O `MercadoLivreClient` aceita `accountId` diretamente. Quando não recebe um ID no construtor, pode obtê-lo de:

- header `X-ML-Account-ID`;
- query string `account_id` ou `ml_account_id`;
- dados POST;
- sessão;
- conta ativa mais recente do banco em execução CLI.

Depois, `loadAccount()` consulta:

```sql
SELECT access_token, refresh_token, ...
FROM ml_accounts
WHERE id = :id
```

Essa consulta não verifica, nesse ponto, se a conta pertence ao usuário autenticado.

Além disso, `ItemController` e `OrderController` permitem substituir a conta ativa por `account_id` vindo da requisição.

### Impacto provável

Um usuário autenticado ou token de API pode, dependendo da rota e do fluxo, fornecer o ID numérico de outra conta e fazer o sistema carregar o token Mercado Livre daquela conta.

Isso poderia causar:

- leitura cruzada de anúncios e pedidos;
- uso indevido do token de outra loja;
- ações na conta errada;
- mistura entre Facility, Falcão e futuros clientes;
- incidente grave de segurança e privacidade.

Esta constatação é resultado de análise estática. Não foi executado teste invasivo contra produção.

### Correção obrigatória

Criar uma política central, por exemplo:

```text
AccountContextResolver
AccountAccessPolicy
```

Essa camada deverá:

1. identificar o usuário por sessão ou Bearer token;
2. receber o `account_id`;
3. validar `ml_accounts.id` junto com o proprietário ou organização;
4. devolver um objeto de contexto já autorizado;
5. impedir que services consultem tokens por ID bruto;
6. exigir identidade explícita nos workers;
7. eliminar o fallback global para “última conta ativa”.

O `MercadoLivreClient` não deverá mais escolher conta diretamente por GET, POST ou header sem validação.

---

## 5. Multi-conta não é ainda multiempresa

O código possui mecanismos de múltiplas contas por usuário e algumas validações em `SessionHelper`, `AccountContextMiddleware` e `MultiAccountManager`.

Porém, não foi identificado um modelo completo com:

- organizações;
- membros;
- papéis por organização;
- lojas;
- contas de marketplace vinculadas à organização;
- isolamento entre empresas;
- permissões por equipe.

Para Facility e Falcão, a estrutura correta deverá ser:

```text
organization
  ├── members
  ├── roles
  ├── marketplace_accounts
  ├── products
  ├── experiments
  ├── tasks
  └── audit_logs
```

A interface esconder dados não é suficiente. O isolamento precisa existir na autorização e no banco.

---

## 6. Integração com Mercado Livre

### Reaproveitamento recomendado

Há valor técnico nos seguintes componentes:

- OAuth;
- armazenamento e renovação de tokens;
- cliente do Mercado Livre;
- sincronização;
- anúncios;
- pedidos;
- perguntas;
- webhooks;
- contas conectadas;
- cache;
- jobs e workers;
- logs;
- testes já existentes.

### Condições antes do reuso

- corrigir o isolamento de contas;
- testar renovação e expiração de tokens;
- validar idempotência;
- implementar retry e dead-letter;
- testar limites da API;
- confirmar endpoints e campos com a documentação oficial atual;
- separar ações de leitura e escrita;
- registrar toda alteração externa;
- impedir execução automática de alto risco.

---

## 7. Camada de inteligência artificial

### Descoberta importante

O projeto já possui uma base de gateway multimodelo. Foram encontrados:

- `LLMService`;
- `AIProviderManager`;
- providers para OpenAI, Claude e Gemini;
- `UnifiedAIService`;
- circuit breaker e fallback;
- registro de uso em alguns fluxos.

Portanto, **não é necessário criar o AI Gateway do zero**.

### Problema

Existem várias camadas concorrentes:

- `AIService` legado, ligado diretamente à OpenAI;
- `LLMService`;
- `UnifiedAIService`;
- `AIProviderManager`;
- clients e services específicos.

Isso produz:

- configuração duplicada;
- modelos hardcoded;
- fallback inconsistente;
- custos calculados de formas diferentes;
- validação de saída desigual;
- dificuldade de auditoria.

### Decisão recomendada

Consolidar tudo em um único gateway interno.

```text
Agentes e serviços
       ↓
AI Gateway interno
       ├── OpenAI
       ├── Claude
       ├── Gemini
       ├── modelos locais
       └── outros provedores
```

O gateway deverá controlar:

- modelo por tarefa;
- custo;
- limite;
- fallback;
- logs;
- cache;
- schema de saída;
- versão do prompt;
- aprovação;
- rastreabilidade.

O `AIService.php` legado deverá ser removido depois da migração de seus consumidores.

---

## 8. NLP: protótipo, não modelo de produção

O microserviço `ml-nlp-service` usa FastAPI, TF-IDF e SVM.

O modelo inicial é treinado automaticamente com apenas 16 frases sintéticas, divididas em quatro classes:

- compatibilidade;
- reclamação crítica;
- logística;
- dúvida geral.

### Problemas

- não há conjunto real de treinamento;
- não há conjunto de validação;
- não há métricas de precisão, recall ou F1;
- não há controle de versão do dataset;
- praticamente não há testes;
- um arquivo de modelo pickle está versionado;
- a chave padrão é `dev-secret-key`;
- quando essa chave padrão é usada, a API não rejeita credenciais erradas ou ausentes;
- mensagens internas de exceção são devolvidas no HTTP 500.

### Parecer

Esse módulo pode ser usado como demonstração técnica, mas não deve classificar automaticamente atendimento real.

Para entrar em produção, será necessário:

1. dataset real e anonimizado;
2. política de rotulagem;
3. treino e validação separados;
4. métricas mínimas;
5. versionamento;
6. autenticação obrigatória;
7. testes;
8. monitoramento de drift;
9. fallback para revisão humana.

---

## 9. Motor de decisão

O `DecisionEngineService` informa no próprio código que:

- usa LLM em decisões de precificação;
- usa fórmulas heurísticas em estoque e campanhas;
- não possui modelos treinados;
- consegue aplicar decisões automaticamente.

### Decisão imediata

Bloquear execução automática de:

- preço;
- estoque;
- campanhas;
- publicidade;
- publicação ou pausa de anúncios.

O módulo poderá permanecer em modo:

```text
observar → analisar → simular → recomendar
```

Só poderá executar após:

- cálculo financeiro oficial;
- aprovação humana;
- feature flag;
- limites mínimos e máximos;
- idempotência;
- logs;
- teste controlado;
- rollback;
- canário;
- avaliação do laboratório de inferência.

---

## 10. SEO e concorrência

### O que pode ser reaproveitado

- coleta de dados;
- estruturas de comparação;
- serviços de título e atributos;
- histórico;
- cache;
- alguns painéis;
- jobs de monitoramento.

### Problemas encontrados

O serviço de concorrência usa heurísticas que podem ser úteis, mas não devem ser tratadas como fatos de mercado.

Exemplos:

- “market share” calculado sobre uma amostra de resultados não é participação real de mercado;
- `sold_quantity` observado não representa necessariamente vendas atuais;
- a média de preços por seller é calculada de maneira incorreta quando há mais de dois itens;
- oportunidades geradas pela IA usam dados agregados sem validação suficiente;
- resultados públicos e endpoints precisam de teste de contrato.

### Correção conceitual

Renomear métricas para refletir o que realmente medem:

```text
participação observada na amostra
posição observada
variação observada
vendas acumuladas expostas
índice heurístico de concorrência
```

O laboratório de inferência deverá guardar evidência, período e limitações de cada métrica.

---

## 11. Rotas e arquitetura do monólito

Foram identificados aproximadamente 1.712 registros de rota.

O Router percorre linearmente todas as rotas a cada requisição. Além do custo, a primeira rota correspondente vence, o que torna duplicações perigosas.

### Duplicações confirmadas

Há 27 combinações de método e caminho registradas mais de uma vez. A maioria aponta para o mesmo controller com sintaxe diferente, mas duas possuem ações realmente diferentes:

```text
GET /api/jobs/{id}
- DashboardController::jobStatus
- JobController::getJob
```

```text
POST /api/seo/analyze
- SEOToolsController::analyze
- SEOApiController::analyze
```

Uma das implementações fica inalcançável dependendo da ordem de carregamento.

### Rotas apontando para ações ausentes

A análise encontrou referências a métodos não localizados nos controllers:

```text
AccountXRayController::exportPdf
QualityController::getPurchaseExperience
```

Essas rotas precisam ser corrigidas ou removidas.

### Parecer

Não acrescentar novos domínios ao Router atual sem antes:

- gerar inventário automático;
- bloquear duplicações no CI;
- separar API por versão;
- criar teste de contrato das rotas;
- considerar roteador consolidado ou framework maduro;
- reduzir controllers e services gigantes.

---

## 12. Banco de dados e migrations

O eSkill usa MySQL. Não recomendo migrar o banco atual para PostgreSQL neste momento.

A nova plataforma de inteligência poderá usar PostgreSQL/Supabase, enquanto o eSkill mantém MySQL e se comunica por API e eventos.

### Problemas encontrados

- tabelas sobrepostas, como `items` e `ml_items`;
- várias migrations de correção e consolidação;
- migrations SQL e PHP misturadas;
- execução não totalmente transacional;
- desativação temporária de foreign keys;
- erros considerados toleráveis;
- rollback que remove o registro da migration, mas não desfaz necessariamente o schema;
- as migrations não são totalmente reaplicáveis em banco vazio;
- o próprio CI usa um snapshot de schema para contornar isso.

### Decisão recomendada

- congelar criação desordenada de migrations;
- criar baseline oficial;
- documentar o schema atual;
- testar restauração em banco limpo;
- adotar migrations determinísticas;
- criar backup e restore testados;
- não fazer conversão de banco junto com a estabilização.

---

## 13. Testes e CI/CD

### Estrutura existente

O projeto possui:

- 305 arquivos de teste PHPUnit;
- Playwright;
- testes unitários;
- testes de integração;
- testes end-to-end;
- workflows no GitHub Actions;
- Codacy;
- Composer Audit;
- build de imagem Docker.

Isso é reaproveitável.

### Limites desta auditoria

Não foi possível executar a suíte completa no ambiente desta análise porque o ZIP não contém `vendor/`, o Composer não está instalado no ambiente e não há acesso externo para baixar dependências.

Foi realizada uma validação parcial de sintaxe PHP:

- 555 arquivos verificados;
- nenhum erro de sintaxe encontrado nessa amostra.

Isso não equivale à aprovação da suíte.

### Problemas no CI

- um workflow executa migrations com `|| true`, podendo ocultar falhas;
- existem workflows sobrepostos;
- o deploy ocorre em push para `master` quando secrets estão configurados;
- o comentário informa que alterações também são feitas diretamente no servidor de produção;
- o SSH usa `StrictHostKeyChecking=no`;
- o processo de release ainda não é imutável;
- testes marcados como skipped precisam ser inventariados;
- o cache do PHPUnit registra defeitos históricos, mas não comprova o estado atual.

### Decisão recomendada

Nenhuma implantação automática em produção até existir:

```text
pull request
→ lint
→ análise estática
→ unitários
→ integração
→ migrations em banco descartável
→ Playwright
→ segurança
→ imagem imutável
→ homologação
→ aprovação
→ produção
```

---

## 14. Infraestrutura

### Pontos positivos

- Dockerfile multi-stage;
- document root apontando para `public/`;
- MySQL e Redis separados;
- health checks;
- volume de storage;
- imagem de produção sem testes e documentação.

### Riscos

- valores padrão fracos para senhas de produção;
- health check do Redis pode falhar quando senha estiver habilitada;
- execução do Apache como `www-data` precisa ser validada em runtime;
- comentários e versões divergem em alguns arquivos;
- scripts de setup e debug continuam no diretório público, embora estejam bloqueados por `exit`;
- editar diretamente em produção contraria a governança aprovada.

Scripts desativados deverão ser removidos da imagem, não apenas deixados com HTTP 404.

---

## 15. Higiene do repositório

### Problemas

- o `ml-nlp-service/venv` foi incluído no repositório e ocupa aproximadamente 376 MB;
- há `__pycache__`;
- existem logs, caches e artefatos de runtime;
- `.env.ai` está versionado com placeholders;
- o README principal descreve mais um kit de agentes no VS Code do que o produto;
- inventários como `services_em_uso.txt` contêm duplicações;
- `rotas_ativas.txt` não representa o estado real.

### Ações

- remover o virtualenv;
- revisar `.gitignore`;
- remover caches e artefatos;
- executar scanner de segredos no histórico Git;
- rotacionar qualquer segredo que tenha sido publicado;
- tornar o repositório privado antes de conectar dados reais;
- criar README de produto;
- gerar inventários automaticamente no CI.

---

## 16. Matriz resumida

| Área | Parecer |
|---|---|
| OAuth e tokens ML | Reaproveitar após correção de autorização |
| Cliente Mercado Livre | Reaproveitar e endurecer |
| Anúncios, pedidos e perguntas | Reaproveitar por API interna |
| Webhooks e workers | Reaproveitar após testes |
| Multi-conta | Adaptar; ainda não é multiempresa |
| SEO | Reaproveitar partes e refatorar |
| Concorrência | Adaptar e corrigir métricas |
| AI Provider Manager | Reaproveitar como base |
| Camadas de IA duplicadas | Consolidar |
| Decision Engine | Quarentena para ações automáticas |
| NLP SVM | Protótipo; retrainar ou substituir |
| Router próprio | Refatorar |
| Migrations | Estabilizar |
| Testes | Reaproveitar e tornar obrigatórios |
| CRM | Construir separado |
| Hermes | Construir separado |
| Laboratório de inferência | Construir separado |
| ERP | Sistema externo soberano de estoque |

---

## 17. Decisões recomendadas

1. O eSkill será preservado como **Marketplace Core**.
2. Nenhuma função de escrita automática será habilitada nesta fase.
3. A falha de autorização de conta será tratada como prioridade máxima.
4. Facility será o primeiro piloto.
5. Falcão só entrará depois do teste de isolamento entre organizações.
6. O banco MySQL do eSkill será mantido inicialmente.
7. A nova plataforma poderá usar PostgreSQL/Supabase.
8. A comunicação ocorrerá por API versionada e eventos.
9. O gateway de IA existente será consolidado, não reconstruído do zero.
10. O laboratório de inferência ficará fora do monólito.
11. O ERP será o dono do estoque.
12. Todo deploy deverá passar por GitHub Actions, homologação e aprovação.

---

## 18. Estado atual

**Aproveitamento técnico:** alto
**Prontidão para expansão:** baixa a média
**Prontidão para multiempresa:** insuficiente sem correções
**Prontidão para automação autônoma:** não aprovada
**Valor principal:** integração operacional acumulada com Mercado Livre
**Risco principal:** autorização e isolamento de contas
**Estratégia:** estabilizar, encapsular e integrar — não reescrever tudo e não ampliar o monólito.
