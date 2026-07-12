[CmdletBinding()]
param()

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

$resetScript = (Resolve-Path -LiteralPath (Join-Path $PSScriptRoot 'reset-staging-database.sh')).Path
$masterSyncScript = (Resolve-Path -LiteralPath (Join-Path $PSScriptRoot 'sync-staging-master-data.sh')).Path
$keyPath = Join-Path $HOME '.ssh\valzeria_staging_deploy'
if (-not (Test-Path -LiteralPath $keyPath -PathType Leaf)) {
    throw "SSH private key is missing: $keyPath"
}

$incomingDir = "$deployRoot/deploy-incoming"
$remote = "$sshUser@$sshHost"
$sshOptions = @('-i', $keyPath, '-p', $sshPort, '-o', 'BatchMode=yes', '-o', 'IdentitiesOnly=yes', '-o', 'StrictHostKeyChecking=yes')
$scpOptions = @('-i', $keyPath, '-P', $sshPort, '-o', 'BatchMode=yes', '-o', 'IdentitiesOnly=yes', '-o', 'StrictHostKeyChecking=yes')

Invoke-CheckedCommand 'ssh.exe' ($sshOptions + @($remote, 'true'))
Invoke-CheckedCommand 'ssh.exe' ($sshOptions + @($remote, "mkdir -p '$incomingDir'"))
Invoke-CheckedCommand 'scp.exe' ($scpOptions + @($resetScript, "${remote}:$incomingDir/reset-staging-database.sh"))
Invoke-CheckedCommand 'scp.exe' ($scpOptions + @($masterSyncScript, "${remote}:$incomingDir/sync-staging-master-data.sh"))

$remoteCommand = "chmod 700 '$incomingDir/reset-staging-database.sh' '$incomingDir/sync-staging-master-data.sh' && DEPLOY_ROOT='$deployRoot' DEPLOY_PHP_BINARY='$phpBinary' bash '$incomingDir/reset-staging-database.sh'"
Invoke-CheckedCommand 'ssh.exe' ($sshOptions + @($remote, $remoteCommand))
