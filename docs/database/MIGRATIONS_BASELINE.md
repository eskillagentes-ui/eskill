# Baseline de Migrations (eSkill / MySQL)

**Status:** Documentado
**Data:** 2026-07-17
**Decisão:** não migrar o eSkill para PostgreSQL agora; CI usa snapshot.

## Fonte da verdade em CI

- Snapshot: [`database/ci/schema.sql`](../../database/ci/schema.sql)
- Lista histórica: [`migrations.txt`](../../migrations.txt) (~122 entradas)
- Migrations SQL incrementais: `database/migrations/*.sql`
- Runner PHP: `database/run_migration.php`

O CI **não** reaplicam todas as migrations do zero em toda execução. Restaura o snapshot e valida o código contra esse schema.

## Regras a partir de agora

1. Novas tabelas/colunas entram como SQL em `database/migrations/YYYY_MM_DD_*.sql`.
2. Atualizar o snapshot `database/ci/schema.sql` na mesma PR (ou job dedicado).
3. Não desativar FKs em produção sem ADR.
4. Rollback deve ser documentado no próprio arquivo SQL (comentário `-- DOWN:`).
5. Nunca persistir `access_token` / `refresh_token` em tabelas de domínio (ex.: `ml_sales_blockers`).

## Migration desta entrega

- `database/migrations/2026_07_17_ml_sales_blockers.sql` — fila operacional de irregularidades (PLAN-IRR-001).

Aplicar em ambientes:

```bash
mysql -u "$DB_USER" -p"$DB_PASS" "$DB_NAME" < database/migrations/2026_07_17_ml_sales_blockers.sql
```
