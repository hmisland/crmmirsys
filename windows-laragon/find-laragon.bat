@echo off
REM Sets LARAGON, PHP (php.exe of Laragon) and MYSQLBIN (Laragon MySQL/MariaDB bin folder).
REM Laragon folder: first argument, else environment variable LARAGON, else C:\laragon.
if not "%~1"=="" set "LARAGON=%~1"
if "%LARAGON%"=="" set "LARAGON=C:\laragon"
set "PHP="
set "MYSQLBIN="
for /d %%D in ("%LARAGON%\bin\php\php-*") do if exist "%%D\php.exe" set "PHP=%%D\php.exe"
for /d %%D in ("%LARAGON%\bin\mysql\*") do if exist "%%D\bin\mysql.exe" set "MYSQLBIN=%%D\bin"
exit /b 0
