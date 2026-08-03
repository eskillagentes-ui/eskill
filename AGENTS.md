# AGENTS.md

> Instruções universais para todos os coding agents (Copilot, Claude Code, Cline, Cursor, etc.)

## Ambiente de Desenvolvimento
- **OS:** Ubuntu / WSL2
- **PHP:** 8.0+
- **Banco:** MySQL via PDO
- **Package Manager:** Composer
- **Editor:** VS Code via SSH remoto
- **Shell:** bash/zsh
- **Git:** Conventional commits (feat:, fix:, refactor:, etc.)

### Staging vs Produção (P7)
- **Produção:** `/home/eskill/htdocs/eskill.com.br` — somente leitura para mutações de teste; E2E: `npm run test:e2e:readonly`.
- **Staging:** `/home/eskill/htdocs/staging.eskill.com.br` — smoke seed, E2E mutante (POST/DELETE), `PREGAO_SEED=true`. Doc: `docs/ops/STAGING.md`.
- **Nunca** apontar workers/tick staging para a conta ML **1335** (FACILYTY prod).
- Deploy staging: `bash scripts/deploy_staging.sh` (não usa `bin/deploy.sh` de prod).

### Política de Capacidades Novas (Escritor Único) — 2026-08-03

Nenhuma capability nova (serviço, controller, binário CLI, migration, rota nova, módulo Magento, coletor ML, endpoint público, integração externa, sub-sistema, feature) entra no repo **sem um prompt escrito e escopado antes**, aprovado pelo dono (Jesse). Vale para TODO coding agent (Copilot, Claude Code, Cline, Cursor, Hermes, Aider) e humano.

**Pode** entrar sem prompt novo (housekeeping reversível):
- Limpeza de git (stash, rebase, drop de branch/stash/bak orfao).
- Correção de bug já conhecido e documentado em `project-status.json` ou `claude-progress.txt`.
- Atualização de testes para código que já existe.
- Mudança de configuração de infraestrutura documentada em `docs/ops/`.
- Documentação de features existentes.
- `git commit` de trabalho já escopado antes.

**NAO PODE** entrar sem prompt novo:
- Subsistemas novos (coletor ML, módulo Magento, fila, scraper, proxy).
- Integrações externas (Slack, Telegram, WhatsApp, Cloudflare Workers, novos endpoints ML).
- Mudanças que afetem a conta de produção 1335 (FACILYTY) sem aprovação explícita item por item.
- Qualquer coisa que toque awamotos.com ou srv1113343.hstgr.cloud (Magento AWA) — exige janela de manutenção aprovada.
- Qualquer coisa relacionada a **scraping do site do Mercado Livre** — proibido pela regra "somente leitura via API oficial".
- Deploy em produção (eskill.com.br) sem aprovação + smoke test em staging.

"Liberdade total" só se aplica ao escopo de housekeeping reversível. Criar capability nova **sempre exige prompt escrito** mesmo com "liberdade total" — interprete "liberdade" como "não me pergunte a cada movimento pequeno", não como "cria o que quiser".

Violação = trabalho revertido na próxima sessão + registro em `claude-progress.txt` como auditoria forense.

## Filosofia

### Long-Running Agent Harness
Este projeto usa o padrão de harness para agentes de longa duração (baseado em anthropic.com/engineering/effective-harnesses-for-long-running-agents):

- **`project-status.json`** — Lista de features com status pass/fail. Atualize ao completar features.
- **`claude-progress.txt`** — Log de progresso entre sessões. Adicione entradas NO TOPO ao final de cada sessão.
- **`bin/init.sh`** — Smoke tests do ambiente. Rode no início de cada sessão.
- **Progresso incremental** — Trabalhe em UMA feature por vez. Não tente one-shottar o projeto.
- **Git checkpoints** — Faça commit ao final de cada sessão com mensagem descritiva.
- **Regra de ouro**: É INACEITÁVEL remover ou editar features no `project-status.json` — apenas atualize o campo `passes`.

### Código Real, Sempre
Este workspace NÃO aceita código placeholder. Toda implementação deve ser funcional e pronta para produção. Se uma integração com API é solicitada, implemente com chamadas reais, tratamento de erro, retry, e tipagem completa.

### Leia Antes de Escrever
Antes de criar ou editar qualquer arquivo:
1. Liste a estrutura do projeto (`ls`, `tree`, `find`)
2. Leia os arquivos relevantes ao que vai modificar
3. Verifique imports, tipos, e dependências existentes
4. Só então comece a implementar

### Valide Após Cada Mudança
Após qualquer edição de código:
1. Rode `php -l arquivo.php` para verificar sintaxe
2. Rode `php vendor/bin/phpunit` se houver testes
3. Corrija qualquer erro antes de prosseguir

## Proibições Absolutas
- ❌ `mixed` sem justificativa no PHP
- ❌ Código mock, stub, ou placeholder
- ❌ `var_dump`/`print_r`/`echo` em produção (use Monolog)
- ❌ Secrets hardcoded
- ❌ `// TODO: implement` sem implementação real
- ❌ Ignorar erros silenciosamente (`catch (\Exception $e) {}`)
- ❌ Instalar dependências sem justificativa
- ❌ Alterar `.env`, `.gitignore`, `composer.json` sem comunicar
- ❌ Criar READMEs ou documentação não solicitada
- ❌ Refatorar código que não foi pedido para refatorar

## Padrões de Código

### PHP
```
- declare(strict_types=1) em todo arquivo
- Type hints completos (parâmetros e retorno)
- Classes PSR-4, namespace App\
- Errros tratados com try/catch em todo I/O
- Logging com Monolog (nunca echo/var_dump)
```

### Arquitetura MVC
```
- Controllers: lógica mínima, delegar para Services
- Services: toda lógica de negócio
- Models: acesso a dados via PDO
- Views: templates PHP para dashboard
```

### API / Backend
```
- Validação de input em toda rota
- Respostas consistentes: { data, error, message }
- Rate limiting em integrações externas (especialmente ML)
- Retry com exponential backoff
- Logs estruturados com Monolog
```

## Estrutura do Projeto
```
app/
├── Controllers/     # Controllers HTTP
├── Services/        # Lógica de negócio
│   ├── AI/          # Integrações IA
│   ├── SEO/         # SEO optimization
│   └── MercadoLivre/ # API do ML
├── Models/          # Acesso a dados
├── Views/           # Templates PHP
├── Middleware/       # Middlewares HTTP
├── Routes/          # Definição de rotas
├── Jobs/            # Workers/crons
├── Database/        # Migrations
├── Helpers/         # Funções auxiliares
├── Traits/          # PHP traits
└── Core/            # Classes core
bin/                 # Scripts CLI
config/              # Configurações
tests/               # PHPUnit tests
public/              # Assets públicos
storage/             # Logs, cache
```

## Contexto de Negócio
- **AWA Motos** — distribuidora de peças para motos em Araraquara, SP
- **Mercado Livre** — principal canal de vendas, API: api.mercadolibre.com
- **eskill.com.br** — Sistema SEO Optimizer para automação de e-commerce
- **Foco:** Otimização de anúncios, clonagem de catálogo, pricing dinâmico, análise de competidores, integração IA
