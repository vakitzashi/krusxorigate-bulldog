@echo off
setlocal
cd /d "%~dp0"
title ORIGATE TACTIC - BeGet deploy

powershell.exe -NoLogo -NoProfile -ExecutionPolicy Bypass -File "%~dp0deploy-beget.ps1"
set "DEPLOY_EXIT=%ERRORLEVEL%"

echo.
if not "%DEPLOY_EXIT%"=="0" echo Deployment failed. Exit code: %DEPLOY_EXIT%
echo Press any key to close this window.
pause >nul
exit /b %DEPLOY_EXIT%
