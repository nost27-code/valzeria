param(
    [int]$Port = 8000,
    [switch]$NoVite
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

$php = (Get-Command php -ErrorAction Stop).Source
$pwsh = (Get-Command pwsh -ErrorAction SilentlyContinue)
if (!$pwsh) {
    $pwsh = Get-Command powershell -ErrorAction Stop
}
$npm = Get-Command npm -ErrorAction SilentlyContinue

if (!(Test-Path -LiteralPath '.env')) {
    throw ".env was not found. Run scripts/local-setup.ps1 first."
}

$listener = Get-NetTCPConnection -LocalAddress '127.0.0.1' -LocalPort $Port -State Listen -ErrorAction SilentlyContinue
if ($listener) {
    throw "Port $Port is already in use."
}

$logDir = Join-Path $Root 'storage\logs'
if (!(Test-Path -LiteralPath $logDir)) {
    New-Item -Path $logDir -ItemType Directory -Force | Out-Null
}
$runnerDir = Join-Path $Root 'storage\temp\local-dev'
if (!(Test-Path -LiteralPath $runnerDir)) {
    New-Item -Path $runnerDir -ItemType Directory -Force | Out-Null
}

$rootEscaped = $Root.Path.Replace("'", "''")

if (!$NoVite) {
    if (!$npm) {
        throw "npm was not found. Use -NoVite or install Node.js."
    }

    $viteOut = Join-Path $logDir 'local-vite.out.log'
    $viteErr = Join-Path $logDir 'local-vite.err.log'
    $viteRunner = Join-Path $runnerDir 'vite.ps1'
    Set-Content -LiteralPath $viteRunner -Encoding UTF8 -Value @"
`$ErrorActionPreference = 'Stop'
Set-Location -LiteralPath '$rootEscaped'
`$env:Path = 'C:\laragon\bin\nodejs\node-v22;' + `$env:Path
npm run dev
"@
    $viteProcess = Start-Process -FilePath $pwsh.Source -ArgumentList @(
        '-NoProfile',
        '-ExecutionPolicy',
        'Bypass',
        '-File',
        $viteRunner
    ) -WorkingDirectory $Root -RedirectStandardOutput $viteOut -RedirectStandardError $viteErr -WindowStyle Hidden -PassThru
}

$serveOut = Join-Path $logDir 'local-serve.out.log'
$serveErr = Join-Path $logDir 'local-serve.err.log'
$serveRunner = Join-Path $runnerDir 'serve.ps1'
$phpEscaped = $php.Replace("'", "''")
Set-Content -LiteralPath $serveRunner -Encoding UTF8 -Value @"
`$ErrorActionPreference = 'Stop'
Set-Location -LiteralPath '$rootEscaped'
& '$phpEscaped' artisan serve --host=127.0.0.1 --port=$Port
"@
$serveProcess = Start-Process -FilePath $pwsh.Source -ArgumentList @(
    '-NoProfile',
    '-ExecutionPolicy',
    'Bypass',
    '-File',
    $serveRunner
) -WorkingDirectory $Root -RedirectStandardOutput $serveOut -RedirectStandardError $serveErr -WindowStyle Hidden -PassThru

Write-Host "Laravel server: http://127.0.0.1:$Port"
Write-Host "Laravel PID: $($serveProcess.Id)"
if (!$NoVite) {
    Write-Host "Vite PID: $($viteProcess.Id)"
}
Write-Host "Logs: storage/logs/local-serve.*.log"
