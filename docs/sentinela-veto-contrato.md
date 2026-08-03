# Contrato de veto do Sentinela

**Data:** 2026-08-03  
**Escopo:** fundação para Criador (Robô 1) e Otimizador (Robô 2)  
**Modo:** somente leitura no Mercado Livre

## Intenção

Antes de **criar anúncio** ou **escalar Ads**, os robôs de expansão consultam o Sentinela.
Se a conta estiver em risco (semáforo amarelo ou vermelho), a expansão é vetada até o risco voltar ao verde.

Nesta entrega, o contrato é **exposto e testado**, mas **nenhum robô consome** ainda.

## API

```php
use App\Services\Sentinela\Sentinela;

$s = new Sentinela();

$s->podeExpandir(int $accountId): bool
// true  → semáforo verde (todos os riscos monitorados <50% do limite, ou qualitativos verdes)
// false → semáforo amarelo ou vermelho

$s->motivoVeto(int $accountId): ?string
// null quando podeExpandir() === true
// mensagem humana com o risco mais crítico quando há veto
```

## Semáforo global

| Cor | Regra |
|---|---|
| **verde** | todos os riscos com dado < 50% do limite ML (e qualitativos verdes) |
| **amarelo** | algum risco entre 50–80% do limite (ou qualitativo amarelo) |
| **vermelho** | algum risco > 80% do limite (ou qualitativo vermelho) |

Riscos `nd` (ex.: NF pendente) **não** entram no cálculo do semáforo nem geram veto sozinhos.

## Limiares (50% do penhasco ML)

| Risco | Limite ML (aprox.) | Alarme (amarelo) | Vermelho |
|---|---:|---:|---:|
| Reclamações | 2% | 1% | 1,6% |
| Atrasos | 15% | 7% | 12% |
| Cancelamentos | 2,5% | 1% | 2% |
| Demais | ver `config/sentinela.php` | 50% do limite operacional | 80% |

## Persistência

- `sentinela_risk_state` — valor atual por risco
- `sentinela_risk_daily` — série para sparkline / Pregão
- `op` via `PregaoEmitService::emitOpOnTransition()` — uma vez por mudança de estado

## Fora de escopo desta fundação

- Consumo por Criador/Otimizador
- Qualquer ação corretiva automática
- Escrita na API do Mercado Livre
