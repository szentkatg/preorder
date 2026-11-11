@echo off
setlocal EnableExtensions DisableDelayedExpansion
cd /d "%~dp0"
if errorlevel 1 exit /b 1
if not exist "logs\" mkdir "logs"
if not exist "logs\" exit /b 1
set "PARTNERPOOL_LOG=%~dp0logs\partnerpool-production.log"
set "PARTNERPOOL_PYTHON=%~dp0.venv-partnerpool\Scripts\python.exe"
set "PARTNERPOOL_CONFIG=%~dp0partnerpool-sync.production.config.json"
>>"%PARTNERPOOL_LOG%" echo [%date% %time%] PRODUCTION START || exit /b 1
if not exist "%PARTNERPOOL_PYTHON%" goto missing_files
if not exist "%PARTNERPOOL_CONFIG%" goto missing_files
"%PARTNERPOOL_PYTHON%" "%~dp0sync_partnerpool.py" --config "%PARTNERPOOL_CONFIG%" --expect-environment production --expect-database szeganet_elorendeles --apply --allow-production >>"%PARTNERPOOL_LOG%" 2>&1
set "PARTNERPOOL_EXIT_CODE=%errorlevel%"
if not "%PARTNERPOOL_EXIT_CODE%"=="0" goto failed
>>"%PARTNERPOOL_LOG%" echo [%date% %time%] PRODUCTION OK || exit /b 1
echo PRODUCTION OK. Log: "%PARTNERPOOL_LOG%"
exit /b 0
:missing_files
set "PARTNERPOOL_EXIT_CODE=2"
>>"%PARTNERPOOL_LOG%" echo Missing virtualenv Python or PRODUCTION configuration. No fallback is allowed.
:failed
>>"%PARTNERPOOL_LOG%" echo [%date% %time%] PRODUCTION ERROR %PARTNERPOOL_EXIT_CODE%
echo PRODUCTION ERROR %PARTNERPOOL_EXIT_CODE%. Check "%PARTNERPOOL_LOG%"
exit /b %PARTNERPOOL_EXIT_CODE%
