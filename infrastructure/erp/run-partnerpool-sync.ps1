[CmdletBinding()]
param(
    [Parameter(Mandatory)][string] $Python,
    [Parameter(Mandatory)][string] $Config,
    [switch] $Apply,
    [switch] $AllowProduction
)
$ErrorActionPreference = 'Stop'
$scriptPath = Join-Path $PSScriptRoot 'sync_partnerpool.py'
$arguments = @($scriptPath, '--config', $Config)
if ($Apply) { $arguments += '--apply' }
if ($AllowProduction) { $arguments += '--allow-production' }
& $Python @arguments
if ($LASTEXITCODE -ne 0) { exit $LASTEXITCODE }
