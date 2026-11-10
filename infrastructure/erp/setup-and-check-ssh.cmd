@echo off
setlocal
set "PARTNERPOOL_PYTHON=%~dp0.venv-partnerpool\Scripts\python.exe"
if not exist "%PARTNERPOOL_PYTHON%" (
    echo ERROR: The .venv-partnerpool Python environment is missing from this folder.
    exit /b 1
)
if not exist "%~dp0partnerpool-sync.config.json" (
    echo ERROR: Keep your existing partnerpool-sync.config.json in this folder.
    exit /b 1
)
echo Updating Python packages for the SSH connection...
"%PARTNERPOOL_PYTHON%" -m pip install --disable-pip-version-check -r "%~dp0requirements-partnerpool.txt"
if errorlevel 1 exit /b 1
echo Backing up and configuring the local SSH settings...
"%PARTNERPOOL_PYTHON%" "%~dp0sync_partnerpool.py" --config "%~dp0partnerpool-sync.config.json" --setup-ssh
if errorlevel 1 exit /b 1
echo Checking SSH and MySQL without publishing data...
"%PARTNERPOOL_PYTHON%" "%~dp0sync_partnerpool.py" --config "%~dp0partnerpool-sync.config.json" --check-target --prompt-passwords
if errorlevel 1 exit /b 1
exit /b 0
