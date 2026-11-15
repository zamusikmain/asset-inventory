# Запуск системы учета оборудования (DEV) и открытие в браузере.
# Использование:  powershell -ExecutionPolicy Bypass -File .\start.ps1  [-NoBrowser] [-Rebuild]
param(
    [switch]$NoBrowser,
    [switch]$Rebuild
)

$ErrorActionPreference = 'Stop'
Set-Location -Path $PSScriptRoot
[Console]::OutputEncoding = [Text.Encoding]::UTF8

$AppUrl  = 'http://localhost:8080'
$MailUrl = 'http://localhost:8025'

function Step($msg) { Write-Host "==> $msg" -ForegroundColor Cyan }
function Ok($msg)   { Write-Host "    $msg" -ForegroundColor Green }
function Fail($msg) { Write-Host "ОШИБКА: $msg" -ForegroundColor Red; Read-Host 'Нажмите Enter для выхода' | Out-Null; exit 1 }

function Test-DockerEngine {
    docker info --format '{{.ServerVersion}}' *> $null
    return ($LASTEXITCODE -eq 0)
}

function Invoke-InPhp([string]$cmd) {
    docker compose exec -T php sh -c $cmd
    if ($LASTEXITCODE -ne 0) { Fail "команда в контейнере завершилась с ошибкой: $cmd" }
}

# 1. Docker
Step 'Проверка Docker'
if (-not (Get-Command docker -ErrorAction SilentlyContinue)) { Fail 'Docker не найден. Установите Docker Desktop.' }
if (-not (Test-DockerEngine)) {
    $dd = Join-Path $env:LOCALAPPDATA 'Programs\DockerDesktop\Docker Desktop.exe'
    if (-not (Test-Path $dd)) { $dd = Join-Path $env:ProgramFiles 'Docker\Docker\Docker Desktop.exe' }
    if (-not (Test-Path $dd)) { Fail 'Docker Desktop не запущен и не найден.' }
    Write-Host '    Запускаю Docker Desktop...'
    Start-Process $dd
    $deadline = (Get-Date).AddMinutes(3)
    while (-not (Test-DockerEngine)) {
        if ((Get-Date) -gt $deadline) {
            Fail 'Docker не поднялся за 3 минуты. Попробуйте: wsl --shutdown, затем перезапустите Docker Desktop.'
        }
        Start-Sleep -Seconds 3
    }
}
Ok 'Docker работает'

# 2. Контейнеры
Step 'Запуск контейнеров'
if ($Rebuild) { docker compose up -d --build } else { docker compose up -d }
if ($LASTEXITCODE -ne 0) { Fail 'docker compose up завершился с ошибкой' }
Ok 'Контейнеры запущены'

# 3. Первый запуск: зависимости, миграции
Step 'Проверка зависимостей и базы данных'
docker compose exec -T php test -f vendor/autoload.php
if ($LASTEXITCODE -ne 0) {
    Write-Host '    Устанавливаю зависимости (первый запуск, несколько минут)...'
    Invoke-InPhp 'COMPOSER_ALLOW_SUPERUSER=1 composer install --no-interaction'
}
Invoke-InPhp 'php bin/console doctrine:migrations:migrate -n --allow-no-migration -q'
Invoke-InPhp 'mkdir -p /var/www/storage && chown -R www-data /var/www/storage /var/www/app/var'

$hasUsers = docker compose exec -T db psql -U app -d assets -tAc 'select count(*) from users'
if ("$hasUsers".Trim() -eq '0') {
    Write-Host '    База пустая - заполняю справочники и демо-данные, создаю администратора'
    Invoke-InPhp 'php bin/console app:seed --demo -q'
    docker compose exec -T php php bin/console app:user:create admin 'Администратор системы'
}
Ok 'База данных готова'

# 4. Ожидание готовности (nginx мог закэшировать старый адрес php -> 502)
Step 'Ожидание готовности приложения'
$ready = $false
$restartedNginx = $false
for ($i = 0; $i -lt 40; $i++) {
    try {
        $r = Invoke-WebRequest -UseBasicParsing -Uri "$AppUrl/health" -TimeoutSec 5
        if ($r.StatusCode -eq 200) { $ready = $true; break }
    } catch {
        $code = $null
        if ($_.Exception.Response) { $code = [int]$_.Exception.Response.StatusCode }
        if ($code -eq 502 -and -not $restartedNginx) {
            docker compose restart nginx | Out-Null
            $restartedNginx = $true
        }
    }
    Start-Sleep -Seconds 2
}
if (-not $ready) { Fail "Приложение не отвечает на $AppUrl/health. Логи: docker compose logs php nginx" }
Ok "Приложение доступно: $AppUrl"

# 5. Браузер
if (-not $NoBrowser) {
    Step 'Открываю браузер'
    Start-Process $AppUrl
}

Write-Host ''
Write-Host "Система учета оборудования:  $AppUrl" -ForegroundColor Green
Write-Host "Почта (mailpit, DEV):        $MailUrl"
Write-Host 'Остановить:                  docker compose stop'
