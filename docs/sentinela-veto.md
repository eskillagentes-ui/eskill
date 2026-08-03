# Contrato de veto do Sentinela — Expandir × Reparar

**Data:** 2026-08-03 (S6.2)  
**Modo:** somente leitura no Mercado Livre · nenhum robô consome ainda

## Regra de ouro

**Reparo nunca é bloqueado por saúde ruim — é a saída dela.**  
O veto existe para impedir que a conta **cresça** enquanto está doente, não para impedi-la de **sarar**.

## API

```php
use App\Services\Sentinela\Sentinela;

$s = new Sentinela();

$s->podeExpandir(int $accountId): bool
// false quando semáforo = amarelo ou vermelho
// Bloqueia CRESCIMENTO

$s->podeReparar(int $accountId): bool
// true SEMPRE, exceto suspensão/bloqueio de conta
// Permite RECUPERAÇÃO

$s->motivoVeto(int $accountId): ?string
// explica por que podeExpandir() está false (null se liberado)
```

## Tabela de decisão

| Ação | Tipo | Método a consultar | Com semáforo vermelho |
|---|---|---|---|
| Criar anúncio novo | **EXPANSÃO** | `podeExpandir()` | bloqueada |
| Entrar em categoria/nicho novo | **EXPANSÃO** | `podeExpandir()` | bloqueada |
| Aumentar orçamento de Ads | **EXPANSÃO** | `podeExpandir()` | bloqueada |
| Ads novo em SKU/nicho novo | **EXPANSÃO** | `podeExpandir()` | bloqueada |
| Escalar operação (mais volume) | **EXPANSÃO** | `podeExpandir()` | bloqueada |
| Ativar promoção em item existente | **REPARO** | `podeReparar()` | liberada |
| Criar/ajustar Ads de recuperação em SKU parado | **REPARO** | `podeReparar()` | liberada |
| Editar ficha / foto / descrição | **REPARO** | `podeReparar()` | liberada |
| Responder pergunta | **REPARO** | `podeReparar()` | liberada |
| Resolver reclamação | **REPARO** | `podeReparar()` | liberada |
| Repor estoque | **REPARO** | `podeReparar()` | liberada |

## Quando `podeReparar()` é false

Somente se a conta estiver **suspensa/bloqueada/banida** (ou equivalente operacional em `ml_accounts.status`), situação em que nem o humano via API consegue recuperar sem intervenção externa (reautorização / suporte ML).

## Semáforo × métodos

| Semáforo | `podeExpandir()` | `podeReparar()` |
|---|---|---|
| verde | true | true* |
| amarelo | false | true* |
| vermelho | false | true* |
| conta suspensa | false† | **false** |

\* salvo suspensão/bloqueio  
† tipicamente também não-verde, mas o bloqueio de reparo é independente do semáforo

## Queda de vendas (S6.1)

- Baseline: média dos últimos até **4 mesmos dias da semana**
- Dia corrente incompleto **excluído**
- 1 dia abaixo (−40%) → amarelo; **3 consecutivos** → vermelho
- Amostra &lt;4 sinalizada no payload (`amostra_reduzida`)

Ver também: `docs/sentinela-veto-contrato.md` (legado S4).
