@echo off
setlocal EnableExtensions EnableDelayedExpansion
title Mirsys CRM - Install (office / LAN edition)
REM Run once, with "Run as administrator" (firewall rule + background jobs).
REM Layout:  <CRM>\windows-offline\install.bat   (this file)
REM          <CRM>\runtime\php\php.exe            (portable PHP 8.1+ x64, you put it here)
REM          <CRM>\runtime\mariadb\bin\mysqld.exe (portable MariaDB x64, you put it here)

set "WO=%~dp0"
for %%I in ("%~dp0..") do set "ROOT=%%~fI"
set "RT=%ROOT%\runtime"
set "PHP=%RT%\php\php.exe"
set "PHPINI=%RT%\php\php.ini"
set "MDB=%RT%\mariadb\bin"
set "DATA=%RT%\data"
set "DBPORT=3307"
set "WEBPORT=8080"

echo.
echo [1/7] Checking runtime ...
if not exist "%PHP%" (
    echo   [ERROR] PHP not found: %PHP%
    echo           Put portable PHP 8.1+ x64 ^(windows.php.net, "Non Thread Safe" zip^) in runtime\php
    goto :fail
)
if not exist "%MDB%\mysqld.exe" (
    echo   [ERROR] MariaDB not found: %MDB%\mysqld.exe
    echo           Put portable MariaDB x64 ^(mariadb.org, "ZIP file"^) in runtime\mariadb
    goto :fail
)
echo   PHP and MariaDB found.
"%PHP%" -v >nul 2>&1
if errorlevel 1 (
    if exist "%RT%\VC_redist.x64.exe" (
        echo   PHP needs Microsoft Visual C++ Runtime - installing it ...
        "%RT%\VC_redist.x64.exe" /install /quiet /norestart
    )
)
"%PHP%" -v >nul 2>&1
if errorlevel 1 (
    echo   [ERROR] PHP does not run. Install Microsoft Visual C++ Redistributable x64 and try again.
    goto :fail
)

echo.
echo [2/7] Preparing php.ini ...
if not exist "%PHPINI%" (
    if exist "%RT%\php\php.ini-production" (copy /y "%RT%\php\php.ini-production" "%PHPINI%" >nul) else (type nul > "%PHPINI%")
    >>"%PHPINI%" echo.
    >>"%PHPINI%" echo ; ---- Mirsys CRM ----
    >>"%PHPINI%" echo extension_dir = "ext"
    for %%E in (pdo_mysql mysqli mbstring openssl curl fileinfo gd zip intl pdo_sqlite sqlite3 sodium) do (
        if exist "%RT%\php\ext\php_%%E.dll" >>"%PHPINI%" echo extension=%%E
    )
    >>"%PHPINI%" echo memory_limit = 256M
    >>"%PHPINI%" echo upload_max_filesize = 32M
    >>"%PHPINI%" echo post_max_size = 40M
    >>"%PHPINI%" echo max_execution_time = 120
    >>"%PHPINI%" echo date.timezone = Asia/Tehran
    echo   php.ini created.
) else (
    echo   php.ini already exists - kept.
)
"%PHP%" -c "%PHPINI%" -r "foreach (['pdo_mysql','mbstring','bcmath','openssl'] as $e) { if (extension_loaded($e) === false) { echo '  [ERROR] PHP extension missing: '.$e.PHP_EOL; exit(1);} } echo '  PHP extensions OK'.PHP_EOL;"
if errorlevel 1 goto :fail

echo.
echo [3/7] Preparing the database server ...
if not exist "%DATA%\mysql" (
    set "INSTALLDB=%MDB%\mariadb-install-db.exe"
    if not exist "!INSTALLDB!" set "INSTALLDB=%MDB%\mysql_install_db.exe"
    "!INSTALLDB!" --datadir="%DATA%" --port=%DBPORT% >nul
    if errorlevel 1 ( echo   [ERROR] Could not initialize the database folder. & goto :fail )
    echo   Database folder created: %DATA%
) else (
    echo   Database folder already exists - kept.
)
call "%WO%start-db.bat"
if errorlevel 1 goto :fail

echo.
echo [4/7] Creating the CRM database and settings ...
if not exist "%ROOT%\.env" (
    for /f "usebackq delims=" %%P in (`powershell -NoProfile -Command "-join ((48..57)+(65..90)+(97..122) | Get-Random -Count 24 | ForEach-Object {[char]$_})"`) do set "DBPASS=%%P"
    for /f "usebackq delims=" %%P in (`powershell -NoProfile -Command "-join ((48..57)+(97..122) | Get-Random -Count 32 | ForEach-Object {[char]$_})"`) do set "MIGTOKEN=%%P"
    "%MDB%\mysql.exe" -u root --port=%DBPORT% -h 127.0.0.1 -e "CREATE DATABASE IF NOT EXISTS crm CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci; CREATE USER IF NOT EXISTS 'crm'@'localhost' IDENTIFIED BY '!DBPASS!'; CREATE USER IF NOT EXISTS 'crm'@'127.0.0.1' IDENTIFIED BY '!DBPASS!'; GRANT ALL ON crm.* TO 'crm'@'localhost'; GRANT ALL ON crm.* TO 'crm'@'127.0.0.1'; FLUSH PRIVILEGES;"
    if errorlevel 1 ( echo   [ERROR] Could not create the database. & goto :fail )
    > "%ROOT%\.env" (
        echo APP_NAME="Mirsys CRM"
        echo APP_ENV=production
        echo APP_DEBUG=false
        echo APP_URL=http://localhost:%WEBPORT%
        echo ORGANIZATION_TIMEZONE=Asia/Tehran
        echo APP_DEFAULT_LANGUAGE=fa
        echo APP_THEME=default
        echo DB_CONNECTION=mysql
        echo DB_HOST=127.0.0.1
        echo DB_PORT=%DBPORT%
        echo DB_DATABASE=crm
        echo DB_USERNAME=crm
        echo DB_PASSWORD=!DBPASS!
        echo SESSION_SECURE_COOKIE=false
        echo SESSION_SAME_SITE=Lax
        echo AUTH_REQUIRE_EMAIL_VERIFICATION=false
        echo MAIL_DRIVER=log
        echo MIGRATE_TOKEN=!MIGTOKEN!
    )
    echo   .env created ^(database user and password are random^).
) else (
    echo   .env already exists - kept.
)

echo.
echo [5/7] Creating / updating the tables ...
"%PHP%" -c "%PHPINI%" "%WO%setup.php" migrate
if errorlevel 1 ( echo   [ERROR] Migration failed. & goto :fail )

echo.
echo [6/7] Network access and background jobs ...
netsh advfirewall firewall show rule name="Mirsys CRM %WEBPORT%" >nul 2>&1
if errorlevel 1 (
    netsh advfirewall firewall add rule name="Mirsys CRM %WEBPORT%" dir=in action=allow protocol=TCP localport=%WEBPORT% profile=private,domain >nul 2>&1
    if errorlevel 1 ( echo   [WARN] Firewall rule not added - run install.bat as administrator. ) else ( echo   Firewall rule added ^(TCP %WEBPORT%^). )
) else (
    echo   Firewall rule already exists.
)
schtasks /create /f /sc minute /mo 1 /ru SYSTEM /tn "MirsysCRM-Cron" /tr "\"%WO%cron.bat\"" >nul 2>&1
if errorlevel 1 ( echo   [WARN] Background jobs not scheduled - run install.bat as administrator. ) else ( echo   Background jobs scheduled every minute ^(task MirsysCRM-Cron^). )

echo.
echo [7/7] Done.
echo ==========================================================
echo   Start the CRM : windows-offline\start.bat
echo   Open http://localhost:8080 - it shows the INSTALL CODE. Send it to the vendor,
echo   install the license file on that page, then register the first account and run:
echo       windows-offline\make-superadmin.bat  your@email
echo ==========================================================
echo.
pause
exit /b 0

:fail
echo.
echo Installation stopped.
pause
exit /b 1
