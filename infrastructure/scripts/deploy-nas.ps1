[CmdletBinding()]
param(
    [string] $Branch,
    [switch] $CheckOnly
)

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'

function Invoke-ProjectGit {
    param([string[]] $GitArguments)

    $output = @(& git -C $script:RepositoryRoot @GitArguments)

    if ($LASTEXITCODE -ne 0) {
        throw ('Local Git command failed: git ' + ($GitArguments -join ' '))
    }

    return ($output -join "`n").Trim()
}

try {
    $script:RepositoryRoot = (Resolve-Path -LiteralPath (Join-Path $PSScriptRoot '..\..')).Path
    $localBranch = Invoke-ProjectGit -GitArguments @('branch', '--show-current')

    if (-not $Branch) {
        $Branch = $localBranch
    }

    if ($Branch -notmatch '^[A-Za-z0-9][A-Za-z0-9._/-]*$') {
        throw 'A regular Git branch name is required; detached HEAD is not supported.'
    }

    $null = Invoke-ProjectGit -GitArguments @('check-ref-format', '--branch', $Branch)

    if ($Branch -ne $localBranch) {
        throw "The local worktree is on '$localBranch', not '$Branch'. Use the script in the matching worktree."
    }

    $origin = Invoke-ProjectGit -GitArguments @('remote', 'get-url', 'origin')
    $originPattern = '^(https://github\.com/|git@github\.com:|ssh://git@github\.com/)szentkatg/preorder(\.git)?/?$'

    if ($origin -notmatch $originPattern) {
        throw 'This command only deploys the szentkatg/preorder repository.'
    }

    $localChanges = Invoke-ProjectGit -GitArguments @('status', '--porcelain', '--untracked-files=no')

    if ($localChanges) {
        if (-not $CheckOnly) {
            throw 'Tracked local changes must be committed and pushed before deployment.'
        }

        Write-Warning 'Tracked local changes exist. CheckOnly inspects the committed HEAD, not those changes.'
    }

    $expectedCommit = Invoke-ProjectGit -GitArguments @('rev-parse', 'HEAD')

    if ($expectedCommit -notmatch '^[0-9a-f]{40}$') {
        throw 'Could not determine the local commit.'
    }

    # Keep all variable data out of the remote shell. These validated values are
    # inserted into PHP, which quotes Git arguments before executing them.
    $remotePhp = @'
$branch = '__BRANCH__';
$expected = '__COMMIT__';
$checkOnly = __CHECK_ONLY__;
$run = static function (string $command): void {
    echo "Running: {$command}\n";
    passthru($command, $status);
    if ($status !== 0) {
        throw new \RuntimeException("Command failed ({$status}): {$command}");
    }
};
$read = static function (string $command): string {
    exec($command . ' 2>&1', $output, $status);
    if ($status !== 0) {
        throw new \RuntimeException("Command failed ({$status}): {$command}\n" . implode("\n", $output));
    }
    return trim(implode("\n", $output));
};
$lock = fopen(sys_get_temp_dir() . '/preorder-nas-deployment.lock', 'c');
if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
    throw new \RuntimeException('Another NAS deployment is already running.');
}
try {
    $nasBranch = $read('git branch --show-current');
    if ($nasBranch !== $branch) {
        throw new \RuntimeException("NAS is on '{$nasBranch}', not '{$branch}'. No branch switch was made.");
    }
    $nasOrigin = $read('git remote get-url origin');
    if (!preg_match('~^(?:https://github\.com/|git@github\.com:|ssh://git@github\.com/)szentkatg/preorder(?:\.git)?/?$~i', $nasOrigin)) {
        throw new \RuntimeException('Unexpected NAS Git repository.');
    }
    if ($read('git status --porcelain --untracked-files=no') !== '') {
        throw new \RuntimeException('Tracked NAS files have local changes. Nothing was overwritten.');
    }
    $before = $read('git rev-parse HEAD');
    $remoteRef = $read('git ls-remote --exit-code origin ' . escapeshellarg('refs/heads/' . $branch));
    $published = preg_split('/\s+/', $remoteRef)[0];
    if ($published !== $expected) {
        throw new \RuntimeException('Local HEAD does not match the published branch. Push first, or update the local worktree.');
    }
    echo "NAS branch: {$branch}\nCurrent commit: {$before}\nTarget commit: {$expected}\n";
    if ($checkOnly) {
        echo "CHECK OK. No Git update, migration, dependency install or cache change was performed.\n";
        return;
    }
    $run('git fetch --no-tags origin ' . escapeshellarg('refs/heads/' . $branch));
    if ($read('git rev-parse FETCH_HEAD') !== $expected) {
        throw new \RuntimeException('The published branch changed during deployment. Rerun after checking the new commit.');
    }
    $changes = $read('git diff --name-only ' . escapeshellarg($before) . ' ' . escapeshellarg($expected));
    if (preg_match('~^(?:resources/(?:js|css)/|vite\.config\.[^/]+$|package(?:-lock)?\.json$|(?:yarn|pnpm)-lock\.[^/]+$)~m', $changes)) {
        throw new \RuntimeException('Frontend build inputs changed. Build on Windows and arrange public/build upload before deployment. The NAS working tree was not updated.');
    }
    $composerChanged = (bool) preg_match('~^composer\.(?:json|lock)$~m', $changes);
    if ($composerChanged) {
        $read('command -v composer');
    }
    // The fetch + fast-forward merge is the controlled equivalent of pull:
    // it installs precisely the commit verified above, even if the branch moves.
    $run('git merge --ff-only ' . escapeshellarg($expected));
    if ($composerChanged) {
        $run('composer install --no-interaction --prefer-dist --optimize-autoloader');
    }
    $run('php artisan optimize:clear');
    $run('php artisan migrate --force');
    $run('php artisan optimize');
    if ($read('git rev-parse HEAD') !== $expected) {
        throw new \RuntimeException('Deployed commit does not match the target.');
    }
    echo 'DEPLOY OK: ' . substr($expected, 0, 7) . " on {$branch}\n";
} finally {
    flock($lock, LOCK_UN);
    fclose($lock);
}
'@

    $remotePhp = $remotePhp.Replace('__BRANCH__', $Branch).Replace('__COMMIT__', $expectedCommit)
    $remotePhp = $remotePhp.Replace('__CHECK_ONLY__', $(if ($CheckOnly) { 'true' } else { 'false' }))
    $encodedPhp = [Convert]::ToBase64String([Text.Encoding]::UTF8.GetBytes($remotePhp))
    $remoteScript = "set -e`nsudo -n /usr/local/sbin/preorder-admin artisan tinker --execute=`"eval(base64_decode('$encodedPhp'));`"`n"
    $sshConfig = Join-Path ([Environment]::GetFolderPath('UserProfile')) '.ssh\config'

    if (-not (Test-Path -LiteralPath $sshConfig -PathType Leaf)) {
        throw "SSH configuration is missing: $sshConfig"
    }

    $sshCommand = (Get-Command ssh.exe -ErrorAction Stop).Source
    $startInfo = New-Object System.Diagnostics.ProcessStartInfo
    $startInfo.FileName = $sshCommand
    $startInfo.Arguments = '-F "' + $sshConfig + '" -p 500 -o BatchMode=yes -o ConnectTimeout=15 -o ServerAliveInterval=15 -o ServerAliveCountMax=3 ds.szeganet.hu sh -s'
    $startInfo.UseShellExecute = $false
    $startInfo.CreateNoWindow = $true
    $startInfo.RedirectStandardInput = $true
    $sshProcess = New-Object System.Diagnostics.Process
    $sshProcess.StartInfo = $startInfo

    Write-Host "NAS deployment: $Branch @ $($expectedCommit.Substring(0, 7))"

    try {
        $null = $sshProcess.Start()
        # Write explicit LF bytes instead of a PowerShell pipe's trailing CRLF.
        $scriptBytes = [Text.Encoding]::UTF8.GetBytes($remoteScript.Replace("`r", ''))
        $sshProcess.StandardInput.BaseStream.Write($scriptBytes, 0, $scriptBytes.Length)
        $sshProcess.StandardInput.Close()
        $sshProcess.WaitForExit()

        if ($sshProcess.ExitCode -ne 0) {
            throw "NAS command failed (SSH exit code $($sshProcess.ExitCode)). Review the output above."
        }
    } finally {
        $sshProcess.Dispose()
    }
} catch {
    Write-Error $_ -ErrorAction Continue
    exit 1
}
