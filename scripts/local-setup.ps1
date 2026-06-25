param(
    [switch]$ResetEnv,
    [switch]$SkipMigrate
)

$ErrorActionPreference = 'Stop'

$Root = Resolve-Path (Join-Path $PSScriptRoot '..')
Set-Location $Root

function Add-IfExistsToPath([string]$PathToAdd) {
    if (Test-Path -LiteralPath $PathToAdd) {
        $env:Path = "$PathToAdd;$env:Path"
    }
}

Add-IfExistsToPath 'C:\laragon\bin\php\php-8.3.30-Win32-vs16-x64'
Add-IfExistsToPath 'C:\laragon\bin\php\php-8.4.22-Win32-vs17-x64'
Add-IfExistsToPath 'C:\laragon\bin\nodejs\node-v22'

function Invoke-NativeCommand([string]$Label, [scriptblock]$Command) {
    & $Command
    if ($LASTEXITCODE -ne 0) {
        throw "$Label failed with exit code $LASTEXITCODE."
    }
}

$php = (Get-Command php -ErrorAction Stop).Source
$node = (Get-Command node -ErrorAction SilentlyContinue)
$npm = (Get-Command npm -ErrorAction SilentlyContinue)

Write-Host "PHP: $php"
if ($node) { Write-Host "Node: $($node.Source)" }
if ($npm) { Write-Host "npm: $($npm.Source)" }

$envPath = Join-Path $Root '.env'
$localEnvPath = Join-Path $Root '.env.local.example'
$backupPath = Join-Path $Root ('.env.backup.local-' + (Get-Date -Format 'yyyyMMdd-HHmmss'))
$envWasPrepared = $false

if (!(Test-Path -LiteralPath $localEnvPath)) {
    throw ".env.local.example was not found."
}

if ($ResetEnv -or !(Test-Path -LiteralPath $envPath)) {
    if (Test-Path -LiteralPath $envPath) {
        Copy-Item -LiteralPath $envPath -Destination $backupPath -Force
        Write-Host "Backed up existing .env: $backupPath"
    }

    Copy-Item -LiteralPath $localEnvPath -Destination $envPath -Force
    Write-Host "Created local .env from .env.local.example."
    $envWasPrepared = $true
} else {
    Write-Host "Keeping existing .env. Use -ResetEnv to switch to the local sandbox env."
}

$database = Join-Path $Root 'database\database.sqlite'
if (!(Test-Path -LiteralPath $database)) {
    New-Item -Path $database -ItemType File -Force | Out-Null
    Write-Host "Created SQLite DB: $database"
}

if (!(Test-Path -LiteralPath (Join-Path $Root 'vendor\autoload.php'))) {
    Write-Host "vendor is missing. Running composer install."
    Invoke-NativeCommand 'composer install' { composer install }
}

if (!(Test-Path -LiteralPath (Join-Path $Root 'node_modules'))) {
    if (!$npm) {
        throw "node_modules is missing and npm was not found. Install Node.js, then rerun this script."
    }
    Write-Host "node_modules is missing. Running npm install --ignore-scripts."
    Invoke-NativeCommand 'npm install' { npm install --ignore-scripts }
}

$envContent = Get-Content -LiteralPath $envPath -Raw
$hasAppKey = $envContent -match '(?m)^APP_KEY=.+'
if ($envWasPrepared -or !$hasAppKey) {
    Invoke-NativeCommand 'artisan key:generate' { & $php artisan key:generate --force }
} else {
    Write-Host "Keeping existing APP_KEY."
}
Invoke-NativeCommand 'artisan config:clear' { & $php artisan config:clear }
Invoke-NativeCommand 'artisan cache:clear' { & $php artisan cache:clear }
Invoke-NativeCommand 'artisan view:clear' { & $php artisan view:clear }

if (!$SkipMigrate) {
    Invoke-NativeCommand 'artisan migrate' { & $php artisan migrate --force }
}

if ($npm) {
    Invoke-NativeCommand 'npm run build' { npm run build }
}

Write-Host ""
Write-Host "Local setup complete."
Write-Host "Start: powershell -ExecutionPolicy Bypass -File scripts/local-dev.ps1"
Write-Host "URL : http://127.0.0.1:8000"
