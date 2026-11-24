[CmdletBinding()]
param(
    [ValidateSet('Both', 'Nas', 'Staging')][string] $Target = 'Both',
    [ValidateSet('Check', 'Deploy', 'Resume')][string] $Mode = 'Check',
    [switch] $Publish,
    [string] $RunId
)

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'
$releaseRoot = (Resolve-Path -LiteralPath (Join-Path $PSScriptRoot '../..')).Path
$releaseTemplate = Get-Content -Raw -Encoding utf8 -LiteralPath (Join-Path $PSScriptRoot 'preorder-release.php')
$releaseTargets = if ($Target -eq 'Both') { @('Nas', 'Staging') } else { @($Target) }
$releaseBranches = @{ Nas = 'feature/pricing-module'; Staging = 'release/pricing-staging-20261029' }

function Invoke-ReleaseGit {
    param([string[]] $Arguments)
    $output = @(& git -C $releaseRoot @Arguments)
    if ($LASTEXITCODE -ne 0) { throw ('Git failed: ' + ($Arguments -join ' ')) }
    return ($output -join [Environment]::NewLine).Trim()
}

function Assert-ReleasePaths {
    param([string[]] $Paths)
    foreach ($path in $Paths) {
        if ($path -match '^(?:\.env(?:$|\.)|public/(?:\.htaccess|error_log)$|composer\.(?:json|lock)$|package(?:-lock)?\.json$|yarn\.lock$|pnpm-lock\.|vite\.config\.|resources/(?:js|css)/|database/migrations/|infrastructure/(?:docker/|compose\.|docker-compose)|docker-compose|\.github/workflows/|\.gitmodules$|\.codex/|\.agents/)') {
            throw "Separate schema/build/dependency/configuration preparation required: $path. No maintenance has started."
        }
    }
}

function Invoke-ReleaseRemote {
    param(
        [ValidateSet('Nas', 'Staging')][string] $Environment,
        [ValidateSet('inspect', 'backup', 'deploy', 'verify', 'health')][string] $Operation,
        [string] $Baseline
    )
    if ($Baseline -notmatch '^[a-f0-9]{40}$') { throw 'Invalid remote baseline' }
    $code = $releaseTemplate.Replace('__TARGET__', $Environment).Replace('__MODE__', $Operation).
        Replace('__COMMIT__', $releaseCommit).Replace('__BASE_COMMIT__', $Baseline).Replace('__RUN_ID__', $RunId)
    $encoded = [Convert]::ToBase64String([Text.Encoding]::UTF8.GetBytes($code))
    $evaluation = "eval(substr(base64_decode('$encoded'), 5));"
    $remoteCommand = if ($Environment -eq 'Nas') {
        'sudo -n /usr/local/sbin/preorder-admin artisan tinker --execute="' + $evaluation + '"' + [char]10
    } else {
        '/opt/alt/php83/usr/bin/php -d memory_limit=512M -r "' + $evaluation + '"' + [char]10
    }
    $sshConfig = Join-Path ([Environment]::GetFolderPath('UserProfile')) '.ssh/config'
    if (-not (Test-Path -LiteralPath $sshConfig -PathType Leaf)) { throw 'The existing pinned SSH configuration is required' }
    $endpoint = if ($Environment -eq 'Nas') { 'ds.szeganet.hu' } else { 'cpanel-szeganet' }
    $port = if ($Environment -eq 'Nas') { 500 } else { 22 }
    $hostOptions = if ($Environment -eq 'Staging') { ' -o HostName=we021.tarhely.com -o HostKeyAlias=szeganet.hu' } else { '' }
    $start = New-Object System.Diagnostics.ProcessStartInfo
    $start.FileName = (Get-Command ssh.exe -ErrorAction Stop).Source
    $start.Arguments = '-F "' + $sshConfig + '" -T -p ' + $port + ' -o BatchMode=yes -o ConnectTimeout=15 -o StrictHostKeyChecking=yes -o ServerAliveInterval=15 -o ServerAliveCountMax=3' + $hostOptions + ' ' + $endpoint + ' sh -s'
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
        $bytes = [Text.Encoding]::UTF8.GetBytes($remoteCommand.Replace([string][char]13, ''))
        $process.StandardInput.BaseStream.Write($bytes, 0, $bytes.Length)
        $process.StandardInput.Close()
        $process.WaitForExit()
        $out = $stdout.GetAwaiter().GetResult()
        $err = $stderr.GetAwaiter().GetResult()
        if ($process.ExitCode -ne 0) { throw "Remote $Environment / $Operation failed: $($err.Trim())" }
        $json = @($out -split '\r?\n' | Where-Object { $_ -match '^\{.*\}$' } | Select-Object -Last 1)
        if ($json.Count -ne 1) { throw "Missing structured result: $Environment / $Operation" }
        Write-Host $json[0]
        return ($json[0] | ConvertFrom-Json)
    } finally { $process.Dispose() }
}

$releaseMutex = New-Object System.Threading.Mutex($false, 'Local\PreorderNasStagingDeployment')
$releaseHasLock = $false
try {
    $releaseHasLock = $releaseMutex.WaitOne(0)
    if (-not $releaseHasLock) { throw 'Another NAS/staging deployment command is running' }
    if ($Publish -and $Mode -ne 'Deploy') { throw '-Publish is only valid with -Mode Deploy' }
    if ($Mode -eq 'Resume' -and -not $RunId) { throw 'Resume requires the RunId printed by the interrupted deployment' }
    if (-not $RunId) { $RunId = [DateTime]::UtcNow.ToString('yyyyMMddTHHmmssZ') + '-' + [Guid]::NewGuid().ToString('N').Substring(0, 12) }
    if ($RunId -notmatch '^[0-9]{8}T[0-9]{6}Z-[a-f0-9]{12}$') { throw 'Invalid RunId' }
    $releaseCommit = Invoke-ReleaseGit -Arguments @('rev-parse', 'HEAD')
    if ($releaseCommit -notmatch '^[a-f0-9]{40}$') { throw 'Invalid local HEAD' }
    if ((Invoke-ReleaseGit -Arguments @('branch', '--show-current')) -ne 'feature/pricing-module') { throw 'Run this command from the feature/pricing-module worktree' }
    $origin = Invoke-ReleaseGit -Arguments @('remote', 'get-url', 'origin')
    if ($origin -notmatch '^(?:https://github\.com/|git@github\.com:|ssh://git@github\.com/)szentkatg/preorder(?:\.git)?/?$') { throw 'Unexpected Git repository' }
    $dirty = Invoke-ReleaseGit -Arguments @('status', '--porcelain', '--untracked-files=all')
    if ($dirty -and $Mode -eq 'Deploy') { throw 'Commit the reviewed local changes first. This command never creates a commit or discards files.' }
    if ($dirty -and $Mode -eq 'Check') { Write-Warning 'Local changes exist. Check describes committed HEAD only; nothing will be deployed.' }
    Write-Host "Preorder $Mode | $Target | $($releaseCommit.Substring(0, 7)) | RunId: $RunId"
    $states = @{}
    foreach ($environment in $releaseTargets) {
        $state = Invoke-ReleaseRemote -Environment $environment -Operation inspect -Baseline $releaseCommit
        if ($state.head -notmatch '^[a-f0-9]{40}$') { throw "Invalid installed commit on $environment" }
        if (@($state.pending_migrations).Count -ne 0) { throw "$environment has pending migrations; prepare them separately" }
        if ($Mode -ne 'Resume' -and $state.maintenance) { throw "$environment is already in maintenance; inspect it before proceeding" }
        $states[$environment] = $state
        if ($Mode -ne 'Resume' -and $state.head -ne $releaseCommit) {
            & git -C $releaseRoot cat-file -e ($state.head + '^{commit}') 2>$null
            if ($LASTEXITCODE -ne 0) { $null = Invoke-ReleaseGit -Arguments @('fetch', '--no-tags', 'origin', ('refs/heads/' + $releaseBranches[$environment])) }
            $null = Invoke-ReleaseGit -Arguments @('merge-base', '--is-ancestor', $state.head, $releaseCommit)
            $changes = Invoke-ReleaseGit -Arguments @('diff', '--name-only', $state.head, $releaseCommit)
            Assert-ReleasePaths -Paths @($changes -split '\r?\n' | Where-Object { $_ })
            if (-not $state.dump_available) { throw "$environment is missing its backup executable" }
        }
    }
    if ($Mode -eq 'Deploy') {
        if ($Publish) {
            $pushArguments = @('push', '--atomic', 'origin') + @($releaseTargets | ForEach-Object { 'HEAD:refs/heads/' + $releaseBranches[$_] })
            $null = Invoke-ReleaseGit -Arguments $pushArguments
        }
        foreach ($environment in $releaseTargets) {
            $published = Invoke-ReleaseGit -Arguments @('ls-remote', '--exit-code', 'origin', ('refs/heads/' + $releaseBranches[$environment]))
            if (($published -split '\s+')[0] -ne $releaseCommit) { throw "Publish the reviewed commit to $environment first, or use -Publish" }
        }
    }
    foreach ($environment in $releaseTargets) {
        if ((Invoke-ReleaseGit -Arguments @('rev-parse', 'HEAD')) -ne $releaseCommit) { throw 'Local HEAD changed during this run' }
        $baseline = $states[$environment].head
        if ($Mode -eq 'Deploy' -and $baseline -ne $releaseCommit) {
            $null = Invoke-ReleaseRemote -Environment $environment -Operation backup -Baseline $baseline
            $null = Invoke-ReleaseRemote -Environment $environment -Operation deploy -Baseline $baseline
            $null = Invoke-ReleaseRemote -Environment $environment -Operation verify -Baseline $baseline
        } elseif ($Mode -eq 'Resume') {
            $baseline = $states[$environment].resume_baseline
            if ($baseline -notmatch '^[a-f0-9]{40}$') { throw "No matching interrupted release was found on $environment" }
            if (-not $states[$environment].verified -or $states[$environment].maintenance) {
                $null = Invoke-ReleaseRemote -Environment $environment -Operation verify -Baseline $baseline
            }
        }
        if ($states[$environment].head -eq $releaseCommit -or $Mode -ne 'Check') {
            $null = Invoke-ReleaseRemote -Environment $environment -Operation health -Baseline $baseline
        }
    }
    Write-Host "PREORDER $($Mode.ToUpperInvariant()) OK: $Target @ $($releaseCommit.Substring(0, 7)). Production was not a target."
} catch {
    Write-Error $_ -ErrorAction Continue
    if ($Mode -ne 'Check' -and $RunId) {
        Write-Host "Stopped safely. RunId: $RunId. No automatic code/database rollback. A failed post-install check can be resumed with the same script, -Mode Resume -RunId $RunId."
    }
    exit 1
} finally {
    if ($releaseHasLock) { $releaseMutex.ReleaseMutex() }
    $releaseMutex.Dispose()
}
