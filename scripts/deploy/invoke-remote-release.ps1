[CmdletBinding()]
param(
    [Parameter(Mandatory = $true)]
    [ValidateSet('staging', 'production')]
    [string] $Target,

    [Parameter(Mandatory = $true)]
    [ValidateSet('none', 'backward_compatible', 'maintenance_required')]
    [string] $MigrationMode,

    [Parameter(Mandatory = $true)]
    [string] $ArchivePath
)

$ErrorActionPreference = 'Stop'

function Assert-EnvironmentValue {
    param([Parameter(Mandatory = $true)][string] $Name)

    $value = [Environment]::GetEnvironmentVariable($Name)
    if ([string]::IsNullOrWhiteSpace($value)) {
        throw "$Name is required."
    }

    return $value
}

function Invoke-CheckedCommand {
    param(
        [Parameter(Mandatory = $true)][string] $Command,
        [Parameter(Mandatory = $true)][string[]] $Arguments
    )

    & $Command @Arguments
    if ($LASTEXITCODE -ne 0) {
        throw "$Command failed with exit code $LASTEXITCODE."
    }
}

$deployRoot = Assert-EnvironmentValue 'DEPLOY_ROOT'
$phpBinary = Assert-EnvironmentValue 'DEPLOY_PHP_BINARY'
$sshHost = Assert-EnvironmentValue 'SSH_HOST'
$sshPort = Assert-EnvironmentValue 'SSH_PORT'
$sshUser = Assert-EnvironmentValue 'SSH_USER'

foreach ($value in @($deployRoot, $phpBinary, $sshHost, $sshPort, $sshUser)) {
    if ($value -notmatch '^[A-Za-z0-9_./:-]+$') {
        throw 'A deploy connection value contains unsupported characters.'
    }
}

$archive = (Resolve-Path -LiteralPath $ArchivePath).Path
$remoteScript = (Resolve-Path -LiteralPath (Join-Path $PSScriptRoot 'remote-release.sh')).Path
$keyName = if ($Target -eq 'staging') { 'valzeria_staging_deploy' } else { 'valzeria_production_deploy' }
$keyPath = Join-Path $HOME ".ssh\$keyName"

if (-not (Test-Path -LiteralPath $keyPath -PathType Leaf)) {
    throw "SSH private key is missing: $keyPath"
}

$releaseId = if ($env:GITHUB_SHA -match '^[0-9a-f]{40}$') { $env:GITHUB_SHA } else { 'manual' }
$releaseName = "release-$releaseId.tar.gz"
$incomingDir = "$deployRoot/deploy-incoming"
$remote = "$sshUser@$sshHost"
$sshOptions = @('-i', $keyPath, '-p', $sshPort, '-o', 'BatchMode=yes', '-o', 'IdentitiesOnly=yes', '-o', 'StrictHostKeyChecking=yes')
$scpOptions = @('-i', $keyPath, '-P', $sshPort, '-o', 'BatchMode=yes', '-o', 'IdentitiesOnly=yes', '-o', 'StrictHostKeyChecking=yes')

Invoke-CheckedCommand 'ssh.exe' ($sshOptions + @($remote, 'true'))
Invoke-CheckedCommand 'ssh.exe' ($sshOptions + @($remote, "mkdir -p '$incomingDir'"))
Invoke-CheckedCommand 'scp.exe' ($scpOptions + @($archive, "${remote}:$incomingDir/$releaseName"))
Invoke-CheckedCommand 'scp.exe' ($scpOptions + @($remoteScript, "${remote}:$incomingDir/remote-release.sh"))

$remoteCommand = "chmod 700 '$incomingDir/remote-release.sh' && DEPLOY_ROOT='$deployRoot' DEPLOY_TARGET='$Target' DEPLOY_ARCHIVE='$incomingDir/$releaseName' DEPLOY_MIGRATION_MODE='$MigrationMode' DEPLOY_PHP_BINARY='$phpBinary' bash '$incomingDir/remote-release.sh'"
Invoke-CheckedCommand 'ssh.exe' ($sshOptions + @($remote, $remoteCommand))
