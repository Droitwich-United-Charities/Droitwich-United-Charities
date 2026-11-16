@echo off
:: Builds DUC-Website.exe (needs Go 1.24+ from https://go.dev/dl/)
cd /d "%~dp0"
go build -trimpath -ldflags "-s -w" -o DUC-Website.exe .
if errorlevel 1 (echo Build failed & pause & exit /b 1)
echo Built DUC-Website.exe
pause
