# Pregão — contrato `ranks` (P4)

## Mudança (envelope v2)

| Campo | Status |
|-------|--------|
| `ranks` | **oficial** — lista `{kw,pos,delta}` ou `[]` |
| `keywords` | **alias deprecado** por 1 versão (= `ranks`) |
| `rank_tracker_enabled` | boolean no snapshot |
| `v` | `2` (antes `1`) |

Quando `RANK_TRACKER_ENABLED=false`:

- snapshot devolve `ranks: []` (nunca `null`)
- fita exibe **"rank tracker desativado"** (não `n/d n/d`)

Remover `keywords` do contrato na próxima versão major do envelope.
