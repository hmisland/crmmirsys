@echo off
REM Starts the bundled MariaDB (localhost only) if it is not running yet. Used by install.bat and start.bat.
setlocal EnableExtensions
for %%I in ("%~dp0..") do set "ROOT=%%~fI"
set "MDB=%ROOT%\runtime\mariadb\bin"
set "DATA=%ROOT%\runtime\data"
"%MDB%\mysqladmin.exe" -u root --port=3307 -h 127.0.0.1 ping >nul 2>&1
if not errorlevel 1 (
    echo   Database server already running.
    exit /b 0
)
start "MirsysCRM Database" /min "%MDB%\mysqld.exe" --datadir="%DATA%" --port=3307 --bind-address=127.0.0.1 --character-set-server=utf8mb4 --collation-server=utf8mb4_unicode_ci
for /l %%N in (1,1,30) do (
    timeout /t 1 /nobreak >nul
    "%MDB%\mysqladmin.exe" -u root --port=3307 -h 127.0.0.1 ping >nul 2>&1 && ( echo   Database server started. & exit /b 0 )
)
echo   [ERROR] The database server did not start.
exit /b 1
