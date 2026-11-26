[CmdletBinding()]
param(
    [ValidateSet('Check', 'Deploy', 'Resume')][string] $Mode = 'Check',
    [ValidatePattern('^[a-f0-9]{40}$')][string] $Commit,
    [switch] $PrepareOrderStatusAudit,
    [string] $RunId
)

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'
$releaseRoot = (Resolve-Path -LiteralPath (Join-Path $PSScriptRoot '../..')).Path
if (-not $Commit) { $Commit = (& git -C $releaseRoot rev-parse HEAD).Trim() }
if ($Commit -notmatch '^[a-f0-9]{40}$') { throw 'Invalid commit' }
& git -C $releaseRoot merge-base --is-ancestor $Commit HEAD
if ($LASTEXITCODE -ne 0) { throw 'Target must be a reviewed local commit' }
if ($Mode -eq 'Resume' -and -not $RunId) { throw 'Resume requires the original RunId' }
if (-not $RunId) { $RunId = [DateTime]::UtcNow.ToString('yyyyMMddTHHmmssZ') + '-' + [Guid]::NewGuid().ToString('N').Substring(0,12) }
if ($RunId -notmatch '^[0-9]{8}T[0-9]{6}Z-[a-f0-9]{12}$') { throw 'Invalid RunId' }
$common = Get-Content -Raw -Encoding utf8 -LiteralPath (Join-Path $PSScriptRoot 'preorder-release.php')
$boundary = $common.IndexOf("if (defined('PREORDER_DEPLOY_LIBRARY_ONLY'))")
if ($boundary -lt 0) { throw 'Shared backup library boundary missing' }
# The shared prefix imports aliases for its NAS/staging main; only its pure functions are needed here.
$library = [regex]::Replace($common.Substring(0, $boundary), '(?m)^use [^\r\n]+;\r?\n', '')
$template = Get-Content -Raw -Encoding utf8 -LiteralPath (Join-Path $PSScriptRoot 'production-release.php')

function Invoke-ProductionPhase {
    param([ValidateSet('inspect','backup','deploy','verify','smoke','health')][string] $Phase)
    $body = $template.Substring(5).Replace('__MODE__', $Phase).Replace('__COMMIT__', $Commit).Replace('__RUN_ID__', $RunId).
        Replace('__PREPARE_ORDER_STATUS_AUDIT__', $(if ($PrepareOrderStatusAudit) { '1' } else { '0' }))
    $encoded = [Convert]::ToBase64String([Text.Encoding]::UTF8.GetBytes($library + $body))
    $command = '/opt/alt/php83/usr/bin/php -d memory_limit=768M -r "eval(substr(base64_decode(''' + $encoded + '''),5));"' + [char]10
    $start = New-Object System.Diagnostics.ProcessStartInfo
    $start.FileName = (Get-Command ssh.exe).Source
    $config = Join-Path ([Environment]::GetFolderPath('UserProfile')) '.ssh/config'
    $start.Arguments = '-F "' + $config + '" -T -p 22 -o BatchMode=yes -o ConnectTimeout=15 -o ServerAliveInterval=15 -o ServerAliveCountMax=3 -o StrictHostKeyChecking=yes -o HostName=we021.tarhely.com -o HostKeyAlias=szeganet.hu cpanel-szeganet sh -s'
    $start.UseShellExecute = $false
    $start.CreateNoWindow = $true
    $start.RedirectStandardInput = $true
    $start.RedirectStandardOutput = $true
    $start.RedirectStandardError = $true
    $process = New-Object System.Diagnostics.Process
    $process.StartInfo = $start
    try {
        $null = $process.Start()
        $stdout = $process.StandardOutput.ReadToEndAsync()
        $stderr = $process.StandardError.ReadToEndAsync()
        $bytes = [Text.Encoding]::UTF8.GetBytes($command.Replace([string][char]13, ''))
        $process.StandardInput.BaseStream.Write($bytes,0,$bytes.Length)
        $process.StandardInput.Close()
        $process.WaitForExit()
        $out = $stdout.GetAwaiter().GetResult()
        $err = $stderr.GetAwaiter().GetResult()
        if ($process.ExitCode -ne 0) { throw "Production $Phase stopped: $($err.Trim())" }
        $json = @($out -split '\r?\n' | Where-Object { $_ -match '^\{.*\}$' } | Select-Object -Last 1)
        if ($json.Count -ne 1) { throw "Missing production result: $Phase" }
        Write-Host $json[0]
        return ($json[0] | ConvertFrom-Json)
    } finally { $process.Dispose() }
}

$mutex = New-Object System.Threading.Mutex($false, 'Local\PreorderProductionDeployment')
$locked = $false
try {
    $locked = $mutex.WaitOne(0)
    if (-not $locked) { throw 'Another production command is running' }
    Write-Host "Production $Mode | $Commit | RunId: $RunId"
    $state = Invoke-ProductionPhase inspect
    if ($Mode -eq 'Check') { Write-Host 'PRODUCTION CHECK OK; no maintenance or deployment performed'; exit 0 }
    if ($Mode -eq 'Deploy') {
        if ($state.maintenance) { throw 'Production is already in maintenance; investigate first' }
        & git -C $releaseRoot push origin ($Commit + ':refs/heads/release/production-20261122')
        if ($LASTEXITCODE -ne 0) { throw 'Production branch publish failed' }
        $null = Invoke-ProductionPhase backup
    }
    $null = Invoke-ProductionPhase deploy
    $null = Invoke-ProductionPhase smoke
    $null = Invoke-ProductionPhase verify
    $null = Invoke-ProductionPhase health
    Write-Host "PRODUCTION DEPLOY OK: $Commit | RunId: $RunId"
} catch {
    Write-Error $_ -ErrorAction Continue
    Write-Host "Stopped. RunId: $RunId. No automatic database rollback. Diagnose before Resume."
    exit 1
} finally {
    if ($locked) { $mutex.ReleaseMutex() }
    $mutex.Dispose()
}
