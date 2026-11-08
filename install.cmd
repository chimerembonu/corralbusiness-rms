@echo off
rem CorralBusiness - RMS: double-click to install (or update) on Windows. Runs install.ps1 next to this file.
powershell -NoProfile -ExecutionPolicy Bypass -File "%~dp0install.ps1" %*
echo.
pause
