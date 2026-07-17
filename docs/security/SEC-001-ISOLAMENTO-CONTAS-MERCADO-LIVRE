# SEC-001 — Isolamento e Autorização de Contas Mercado Livre

**Severidade:** Crítica
**Status:** Aberta
**Data:** 16/07/2026
**Origem:** Auditoria estática do eSkill

---

## Problema

O ID da conta Mercado Livre pode ser obtido diretamente de parâmetros HTTP ou header e usado pelo `MercadoLivreClient` para carregar tokens sem uma verificação central de propriedade.

Arquivos principais:

- `app/Services/MercadoLivreClient.php`;
- `app/Controllers/ItemController.php`;
- `app/Controllers/OrderController.php`;
- `app/Middleware/ApiAuthMiddleware.php`.

---

## Cenário de risco

1. Usuário ou token de API autentica normalmente.
2. Envia `account_id` pertencente a outra conta.
3. Controller cria service com o ID informado.
4. `MercadoLivreClient::loadAccount()` busca tokens por `id`.
5. A propriedade da conta não é validada nessa consulta.

---

## Correção proposta

### Novo contrato

```php
interface AccountAccessPolicy
{
    public function authorize(
        int $actorUserId,
        int $accountId,
        string $capability
    ): AuthorizedAccountContext;
}
```

### Regras

- nenhum controller instancia `MercadoLivreClient` com ID bruto;
- nenhum service carrega token antes de receber `AuthorizedAccountContext`;
- sessão e Bearer token devem produzir a mesma identidade de ator;
- `organization_id` deve ser validado;
- workers devem carregar actor de serviço e organização explícitos;
- toda negação gera log de segurança;
- nenhum erro expõe existência de conta alheia.

---

## Testes obrigatórios

1. usuário A acessa conta A: permitido;
2. usuário A acessa conta B: 403;
3. token de A acessa conta B: 403;
4. usuário sem organização: 403;
5. worker sem account_id explícito: falha;
6. ID inexistente: 404 genérico;
7. conta desativada: acesso negado;
8. mudança de conta gera auditoria;
9. header, GET e POST não contornam a policy;
10. tokens nunca aparecem em logs.

---

## Critério de fechamento

A issue só poderá ser encerrada quando os testes acima passarem em CI e houver revisão de segurança.
