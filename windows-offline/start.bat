@echo off
setlocal EnableExtensions
title MirsysCRM Server - keep this window open
for %%I in ("%~dp0..") do set "ROOT=%%~fI"
set "PHP=%ROOT%\runtime\php\php.exe"
set "PHPINI=%ROOT%\runtime\php\php.ini"

call "%~dp0start-db.bat"
if errorlevel 1 ( pause & exit /b 1 )

echo.
echo ==========================================================
echo   Mirsys CRM (office edition)
echo   This computer : http://localhost:8080
echo   Other computers on this network:
for /f "usebackq delims=" %%I in (`powershell -NoProfile -Command "Get-NetIPAddress -AddressFamily IPv4 -ErrorAction SilentlyContinue | Where-Object { $_.IPAddress -notlike '127.*' -and $_.IPAddress -notlike '169.254.*' } | ForEach-Object { $_.IPAddress }"`) do echo                   http://%%I:8080
echo.
echo   Do NOT close this window - it is the server. To stop: stop.bat
echo ==========================================================
start "" /b cmd /c "timeout /t 3 /nobreak >nul & start http://localhost:8080"
cd /d "%ROOT%"
"%PHP%" -c "%PHPINI%" -S 0.0.0.0:8080 -t public public\index.php
pause
