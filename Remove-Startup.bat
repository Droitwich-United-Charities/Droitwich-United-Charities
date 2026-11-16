@echo off
net session >nul 2>&1 || (echo Please right-click this file and choose "Run as administrator". & pause & exit /b 1)
schtasks /End /TN "DUC Website" >nul 2>&1
schtasks /Delete /TN "DUC Website" /F
echo The DUC website will no longer start automatically.
pause
