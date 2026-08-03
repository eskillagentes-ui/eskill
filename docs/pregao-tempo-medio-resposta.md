# Pregão P3 — tempo médio de resposta (auditoria 2026-08-03)

## Veredito: **REAL** (não é bug de software)

| Campo | Valor |
|-------|-------|
| Card UI | 30.119s → formatado **8h21** |
| `account_index_metrics.tempo_medio_resposta_s` | 30119 |
| `perguntas_hoje` | 0 |
| Janela | últimos **30 dias** |
| Filtro | só perguntas **respondidas** (`answer_date IS NOT NULL`) |
| Não respondidas | **não entram** (tempo aberto não conta) |
| Unidade | segundos (inteiro) |
| Amostra | **8** perguntas respondidas (conta 1335) |

### Amostra (segundos até resposta)

| id | s | ≈ |
|----|---|---|
| 148348 | 7117 | 1h58 |
| 150748 | 1799 | 30m |
| 150798 | 5865 | 1h37 |
| 154513 | **184646** | **~51h** (outlier) |
| 157593 | 31563 | 8h46 |
| 161793 | 3359 | 56m |
| 162523 | 1002 | 17m |
| 162695 | 5600 | 1h33 |

Média = 30118.875s ≈ **8h21**. O outlier de ~51h puxa a média; a fórmula está correta.

## Correção de produto (não altera o número)

1. UI formata com `fmtDuration` → `8h21` (não “30119s”).
2. Se média **> 1h**, emite `op` alerta `PERGUNTAS` (throttle 1×/h + transição de bucket).
3. Problema de **operação** (SLA de resposta), não de coleta.

SQL canônico:

```sql
SELECT AVG(TIMESTAMPDIFF(SECOND, date_created, answer_date))
FROM ml_questions
WHERE account_id = ?
  AND answer_date IS NOT NULL
  AND date_created >= DATE_SUB(NOW(), INTERVAL 30 DAY);
```
