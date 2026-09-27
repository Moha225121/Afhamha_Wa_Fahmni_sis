@echo off
powershell.exe -NoProfile -ExecutionPolicy Bypass -File "%~dp0audit-pwa.ps1" %*
exit /b %ERRORLEVEL%
