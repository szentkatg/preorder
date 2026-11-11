@echo off
setlocal EnableExtensions DisableDelayedExpansion
cd /d "%~dp0"
if errorlevel 1 exit /b 1
if not exist "logs\" mkdir "logs"
if not exist "logs\" exit /b 1
set "PARTNERPOOL_LOG=%~dp0logs\partnerpool-test.log"
set "PARTNERPOOL_PYTHON=%~dp0.venv-partnerpool\Scripts\python.exe"
set "PARTNERPOOL_CONFIG=%~dp0partnerpool-sync.config.json"
>>"%PARTNERPOOL_LOG%" echo [%date% %time%] TEST START || exit /b 1
if not exist "%PARTNERPOOL_PYTHON%" goto missing_files
if not exist "%PARTNERPOOL_CONFIG%" goto missing_files
"%PARTNERPOOL_PYTHON%" "%~dp0sync_partnerpool.py" --config "%PARTNERPOOL_CONFIG%" --expect-environment staging --expect-database szeganet_preorder_test --apply >>"%PARTNERPOOL_LOG%" 2>&1
set "PARTNERPOOL_EXIT_CODE=%errorlevel%"
if not "%PARTNERPOOL_EXIT_CODE%"=="0" goto failed
>>"%PARTNERPOOL_LOG%" echo [%date% %time%] TEST OK || exit /b 1
echo TEST OK. Log: "%PARTNERPOOL_LOG%"
exit /b 0
:missing_files
set "PARTNERPOOL_EXIT_CODE=2"
>>"%PARTNERPOOL_LOG%" echo Missing virtualenv Python or TEST configuration. No fallback is allowed.
:failed
>>"%PARTNERPOOL_LOG%" echo [%date% %time%] TEST ERROR %PARTNERPOOL_EXIT_CODE%
echo TEST ERROR %PARTNERPOOL_EXIT_CODE%. Check "%PARTNERPOOL_LOG%"
exit /b %PARTNERPOOL_EXIT_CODE%
