# Coletor local de rank (T1b)

Roda **no seu PC** (IP residencial). Servidor só orquestra — sem scraping, sem token ML.
No servidor: `RANK_COLLECTOR_LOCAL=true`.

## Pacote pronto (recomendado)

No servidor:
```bash
bash collector/pack-for-pc.sh
```
Baixe `storage/rank-collector-pack/rank-collector-pc.zip` para o PC e siga `RUN-LINUX.txt` ou `RUN-WINDOWS.txt`.

## Linux (repo já clonado)

```bash
set -a && source storage/rank-collector-pack/collector.env && set +a
php collector/rank-collector.php --dry
php collector/rank-collector.php
bash collector/install-linux.sh
```

## Windows

Extraia o zip → carregue `collector.env` no PowerShell → `php .\rank-collector.php` → `.\install-windows.ps1`.

Volume: máx 30 keywords/dia, ~1 min. Sem token ML no PC.
