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

## ⚠️ PROTEÇÕES CRÍTICAS (LEIA ANTES DE MODIFICAR)

### Arquivos que NUNCA podem ser movidos pra `_quarantine/`

Estes arquivos parecem "órfãos" (zero refs diretas em alguns grep) mas são usados via injeção de dependência ou late static binding:

```php
// CRÍTICO - SecureTokenService depende disso
app/Services/EncryptionService.php
```

**Por quê:** O `SecureTokenService` faz `use App\Services\EncryptionService` e instancia via `new EncryptionService()` (não via DI container). Greps que procuram "EncryptionService" só no código principal não vão encontrar — mas qualquer falha quebra a integração com Mercado Livre (todas as contas ficam `disconnected`).

**Lição do commit d4f01175:** Movi `EncryptionService` pra `_quarantine` achando que era órfão. Resultado: 30+ horas sem refresh de token em produção. **NUNCA FAÇA ISSO**.

### Checklist ANTES de mover arquivo pra `_quarantine`

```bash
# 1. Procurar uso direto (use/import)
grep -rn "use App\\\\Services\\\\<ClassName>" app/ --include='*.php' | grep -v _quarantine

# 2. Procurar instanciação direta (new)
grep -rn "new \\\\?App\\\\Services\\\\<ClassName>\\|new <ClassName>\\|new \\\\Services\\\\<ClassName>" app/ --include='*.php' | grep -v _quarantine

# 3. Procurar em traits (pode usar class import)
grep -rn "<ClassName>" app/Traits/ --include='*.php' | grep -v _quarantine

# 4. Procurar via DI container
grep -rn "<ClassName>::class" app/ --include='*.php' | grep -v _quarantine

# 5. Procurar strings (binding names)
grep -rn "'<ClassName>'" app/ --include='*.php' | grep -v _quarantine
```

Se QUALQUER um retornar resultado, **NÃO MOVA**.

### Integração com Mercado Livre — Pontos de Atenção

- **Rate limit:** API ML tem QPS limitado (~10 free, ~1000 verified). Workers que fazem loops devem usar `RateLimitTrackerService` ou batch.
- **Tokens:** Sempre criptografar com `EncryptionService`. NUNCA salvar token em texto puro.
- **Refresh automático:** `UnifiedTokenRefreshService` roda a cada 30min. Se ver `disconnected` no `ml_accounts`, **verificar EncryptionService primeiro**.
- **Webhook de orders:** Processar via `MercadoLivreWebhookController` (não `WebhookController` que foi removido em d4f01175).

### Health Check (bin/ml-health-check.php)

Use para validar o ambiente:
```bash
php bin/ml-health-check.php --account-id=1335
```

Se retornar `errors > 0`, **NÃO mexa** até descobrir o que é.

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
- ❌ **Mover arquivos para `_quarantine` sem rodar checklist de refs** (acima)

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

---

## Histórico de incidentes críticos

### 2026-07-29: Token refresh quebrado (commit d4f01175)
- **Causa:** Cleanup removeu `EncryptionService.php` achando que era órfão
- **Sintoma:** Conta Facilyty ficou 30+ horas `disconnected` 
- **Erro reportado:** `decrypt_failed` (genérico, escondia o problema real)
- **Causa raiz:** `SecureTokenService` usa `new EncryptionService()` — sem DI
- **Fix:** Commit 95b4dcaf restaurou `EncryptionService.php`
- **Lição:** Ver seção "PROTEÇÕES CRÍTICAS" acima antes de qualquer cleanup