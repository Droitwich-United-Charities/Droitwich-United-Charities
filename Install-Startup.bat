@echo off
:: Runs the DUC website automatically when the server starts (needs "Run as administrator")
net session >nul 2>&1 || (echo Please right-click this file and choose "Run as administrator". & pause & exit /b 1)
schtasks /Create /TN "DUC Website" /TR "\"%~dp0DUC-Website.exe\" -service" /SC ONSTART /RU SYSTEM /RL HIGHEST /F
taskkill /IM DUC-Website.exe /F >nul 2>&1
schtasks /Run /TN "DUC Website"
echo.
echo Done. The DUC website now starts automatically with Windows.
pause
