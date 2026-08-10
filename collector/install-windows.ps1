# Instalador Windows — cria tarefa diária 05:15.
# Uso (PowerShell como usuário):
#   $env:RANK_COLLECTOR_KEY='SUA_CHAVE'; .\collector\install-windows.ps1
param(
  [string]$Server = $(if ($env:RANK_COLLECTOR_SERVER) { $env:RANK_COLLECTOR_SERVER } else { 'https://eskill.com.br' }),
  [string]$Key = $env:RANK_COLLECTOR_KEY,
  [string]$Php = $(Get-Command php -ErrorAction SilentlyContinue | Select-Object -ExpandProperty Source)
)
if (-not $Key -or $Key.Length -lt 16) { Write-Error 'Defina RANK_COLLECTOR_KEY (>=16)'; exit 2 }
if (-not $Php) { Write-Error 'php.exe não encontrado no PATH'; exit 2 }
$Script = Join-Path $PSScriptRoot 'rank-collector.php'
$Action = New-ScheduledTaskAction -Execute $Php -Argument "`"$Script`" --server=$Server --key=$Key"
$Trigger = New-ScheduledTaskTrigger -Daily -At 5:15am
Register-ScheduledTask -TaskName 'EskillRankCollector' -Action $Action -Trigger $Trigger -Force | Out-Null
Write-Host 'OK: tarefa EskillRankCollector criada (diária 05:15).'
Write-Host "Dry-run: php `"$Script`" --server=$Server --key=*** --dry"
