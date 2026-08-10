# Coletor local de rank (T1b)

Roda **no seu PC** (IP residencial). Servidor só orquestra — sem scraping, sem token ML.

## 1 comando (Linux)

```bash
export RANK_COLLECTOR_SERVER=https://eskill.com.br RANK_COLLECTOR_KEY='SUA_CHAVE'
php collector/rank-collector.php --dry   # testa
php collector/rank-collector.php         # coleta 1×
echo "15 5 * * * cd /caminho/eskill && php collector/rank-collector.php" | crontab -
```

## Windows (Task Scheduler)

1. Copie `collector/rank-collector.php` para o PC.
2. Crie tarefa diária 05:15: `php.exe C:\path\rank-collector.php --server=https://eskill.com.br --key=SUA_CHAVE`
3. Ou rode `collector/install-windows.ps1` (ajusta caminho/chave).

Flag no servidor: `RANK_COLLECTOR_LOCAL=false` desliga sem quebrar o resto (trends parcial continua).
