@echo off
setlocal EnableExtensions DisableDelayedExpansion
call "%~dp0save-passwords-and-check.cmd" production
exit /b %errorlevel%
