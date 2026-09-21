@echo off
title Git Push Helper

echo.
echo ===============================
echo Git Push Helper
echo ===============================
echo.

git rev-parse --is-inside-work-tree >nul 2>&1

if errorlevel 1 (
    echo FEHLER: Der aktuelle Ordner ist kein Git Repository.
    pause
    exit /b
)

echo Repository:
for /f "delims=" %%i in ('git rev-parse --show-toplevel') do echo %%i

echo.
git status

echo.
set /p MSG=Commit-Nachricht eingeben:

if "%MSG%"=="" (
    echo Commit abgebrochen.
    pause
    exit /b
)

echo.
git add .

git commit -m "%MSG%"

if errorlevel 1 (
    echo.
    echo Kein Commit erforderlich oder Fehler beim Commit.
)

echo.
git push

echo.
echo Fertig.
pause
