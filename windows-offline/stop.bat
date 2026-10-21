@echo off
setlocal EnableExtensions
for %%I in ("%~dp0..") do set "ROOT=%%~fI"
echo Stopping the web server ...
taskkill /fi "WINDOWTITLE eq MirsysCRM Server*" /t /f >nul 2>&1
echo Stopping the database server ...
"%ROOT%\runtime\mariadb\bin\mysqladmin.exe" -u root --port=3307 -h 127.0.0.1 shutdown >nul 2>&1
echo Done.
timeout /t 2 /nobreak >nul
