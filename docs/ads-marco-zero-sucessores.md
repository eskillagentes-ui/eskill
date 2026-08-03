# Marco zero — sucessores de catálogo (Bloco 5 Ads)

Registro da data/hora de ativação de promoção e campanha nos sucessores.
Painel: bloco **Recuperação em curso** em `/dashboard/ads`.

## Sucessores

| Sucessor | Predecessor | Criação catálogo (aprox.) | Promo ativada | Campanha Ads ativada | Fonte |
|---|---|---|---|---|---|
| `MLB7297087912` | `MLB6574414098` | 2026-07-30 | *a registrar* | *a registrar* | `docs/reconciliacao-catalogo-2026-08-03.md` |
| `MLB7314817026` | `MLB6574534100` | 2026-08-02 | *a registrar* | *a registrar* | idem |

Persistido em `ads_recovery_milestones` (seed via `php bin/ads-migrate-bloco5.php`).

## Como atualizar

```sql
UPDATE ads_recovery_milestones
SET promo_activated_at = 'YYYY-MM-DD HH:MM:SS',
    campaign_activated_at = 'YYYY-MM-DD HH:MM:SS'
WHERE account_id = 1335 AND mlb_id = 'MLB…';
```

## TACOS baseline

Valor inicial documentado: **10%** (`AdsMetricsCollector::TACOS_BASELINE_INITIAL`).
Recalculado a partir da janela baseline de 28 dias (sem sobreposição com os 7 dias atuais) quando houver histórico.

## Restrição

Somente leitura na API de Ads/ML. `ML_WRITE_AUTOMATION=false`.
