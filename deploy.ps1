# Развертывание на новом компьютере из архива: восстанавливает базу и файлы из .\transfer,
# затем запускает систему через start.ps1.
# Использование:  powershell -ExecutionPolicy Bypass -File .\deploy.ps1  [-Force]
#   -Force  перезаписать данные, если в базе на этом компьютере уже есть пользователи
param([switch]$Force)

$ErrorActionPreference = 'Stop'
Set-Location -Path $PSScriptRoot
[Console]::OutputEncoding = [Text.Encoding]::UTF8

function Step($msg) { Write-Host "==> $msg" -ForegroundColor Cyan }
function Ok($msg)   { Write-Host "    $msg" -ForegroundColor Green }
function Fail($msg) { Write-Host "ОШИБКА: $msg" -ForegroundColor Red; Read-Host 'Нажмите Enter для выхода' | Out-Null; exit 1 }
function Check($what) { if ($LASTEXITCODE -ne 0) { Fail $what } }

$dump    = Join-Path $PSScriptRoot 'transfer\db.dump'
$storage = Join-Path $PSScriptRoot 'transfer\storage.tgz'
if (-not (Test-Path $dump) -or -not (Test-Path $storage)) { Fail 'Нет transfer\db.dump или transfer\storage.tgz рядом со скриптом.' }

Step 'Проверка Docker'
if (-not (Get-Command docker -ErrorAction SilentlyContinue)) { Fail 'Docker не найден. Установите Docker Desktop и запустите его.' }
docker info --format '{{.ServerVersion}}' *> $null
if ($LASTEXITCODE -ne 0) { Fail 'Docker Desktop не запущен. Запустите его и повторите.' }
Ok 'Docker работает'

Step 'Сборка и запуск контейнеров (первый раз несколько минут)'
docker compose up -d --build; Check 'docker compose up завершился с ошибкой'

Step 'Ожидание базы данных'
$ErrorActionPreference = 'Continue'   # stderr docker/psql в PS 5.1 иначе превращается в исключение
for ($i = 0; $i -lt 60; $i++) {
    docker compose exec -T db pg_isready -U app -d assets *> $null
    if ($LASTEXITCODE -eq 0) { break }
    Start-Sleep -Seconds 2
}
Check 'база данных не поднялась'

$users = docker compose exec -T db psql -U app -d assets -tAc "select count(*) from users" 2>$null
$hasData = ($LASTEXITCODE -eq 0 -and "$users".Trim() -ne '0')
$ErrorActionPreference = 'Stop'
if ($hasData -and -not $Force) {
    Fail 'В базе на этом компьютере уже есть данные. Чтобы перезаписать их, запустите: deploy.cmd -Force'
}

Step 'Восстановление базы данных'
docker compose cp $dump db:/tmp/db.dump; Check 'не удалось скопировать дамп'
docker compose exec -T db pg_restore -U app -d assets --clean --if-exists --no-owner /tmp/db.dump; Check 'pg_restore завершился с ошибкой'
docker compose exec -T db rm -f /tmp/db.dump
Ok 'База восстановлена'

Step 'Восстановление файлов (документы, импорты, ключ шифрования)'
docker compose cp $storage php:/tmp/storage.tgz; Check 'не удалось скопировать архив файлов'
docker compose exec -T php sh -c 'tar xzf /tmp/storage.tgz -C /var/www/storage && rm -f /tmp/storage.tgz && chown -R www-data /var/www/storage'; Check 'не удалось распаковать файлы'
Ok 'Файлы восстановлены'

# Зависимости, миграции, ожидание готовности и открытие браузера
& (Join-Path $PSScriptRoot 'start.ps1')
