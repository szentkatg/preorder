@echo off
setlocal EnableExtensions DisableDelayedExpansion
set "PARTNERPOOL_PYTHON=%~dp0.venv-partnerpool\Scripts\python.exe"
set "PARTNERPOOL_CONFIG=%~dp0partnerpool-sync.config.json"
set "PARTNERPOOL_ENVIRONMENT=staging"
set "PARTNERPOOL_DATABASE=szeganet_preorder_test"
if "%~1"=="" goto config_ready
if /i not "%~1"=="production" exit /b 2
set "PARTNERPOOL_CONFIG=%~dp0partnerpool-sync.production.config.json"
set "PARTNERPOOL_ENVIRONMENT=production"
set "PARTNERPOOL_DATABASE=szeganet_elorendeles"
:config_ready
if not exist "%PARTNERPOOL_PYTHON%" (
    echo ERROR: The .venv-partnerpool Python environment is missing from this folder.
    exit /b 1
)
if not exist "%PARTNERPOOL_CONFIG%" (
    echo ERROR: The selected configuration file is missing. Existing configuration files must not be overwritten.
    exit /b 1
)
echo Saving database passwords with Windows current-user DPAPI...
"%PARTNERPOOL_PYTHON%" "%~dp0sync_partnerpool.py" --config "%PARTNERPOOL_CONFIG%" --expect-environment "%PARTNERPOOL_ENVIRONMENT%" --expect-database "%PARTNERPOOL_DATABASE%" --save-passwords
if errorlevel 1 exit /b 1
echo Checking SSH and MySQL using the saved passwords, without publishing...
"%PARTNERPOOL_PYTHON%" "%~dp0sync_partnerpool.py" --config "%PARTNERPOOL_CONFIG%" --expect-environment "%PARTNERPOOL_ENVIRONMENT%" --expect-database "%PARTNERPOOL_DATABASE%" --check-target
if errorlevel 1 exit /b 1
echo Checking the ERP source using the saved passwords, without publishing...
"%PARTNERPOOL_PYTHON%" "%~dp0sync_partnerpool.py" --config "%PARTNERPOOL_CONFIG%" --expect-environment "%PARTNERPOOL_ENVIRONMENT%" --expect-database "%PARTNERPOOL_DATABASE%"
if errorlevel 1 exit /b 1
echo Saved-password checks completed. No pool data was uploaded by this command.
echo No scheduled task was generated, imported or enabled.
exit /b 0
