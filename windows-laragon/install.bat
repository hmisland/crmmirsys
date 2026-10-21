@echo off
setlocal EnableExtensions EnableDelayedExpansion
title Mirsys CRM - Install on Laragon
REM Run once with "Run as administrator", while Laragon is running ("Start All").
REM Usage: install.bat [Laragon folder]   (default C:\laragon)

set "WL=%~dp0"
for %%I in ("%~dp0..") do set "ROOT=%%~fI"
set "ROOTFWD=%ROOT:\=/%"
set "WEBPORT=8080"
call "%WL%find-laragon.bat" %1

echo.
echo [1/7] Checking Laragon ...
if not exist "%LARAGON%\laragon.exe" ( echo   [ERROR] Laragon not found in %LARAGON% & echo           Usage: install.bat D:\laragon & goto :fail )
if "%PHP%"=="" ( echo   [ERROR] No PHP found in %LARAGON%\bin\php & goto :fail )
if "%MYSQLBIN%"=="" ( echo   [ERROR] No MySQL/MariaDB found in %LARAGON%\bin\mysql & goto :fail )
echo   Laragon : %LARAGON%
echo   PHP     : %PHP%
echo   MySQL   : %MYSQLBIN%
"%PHP%" -r "if (version_compare(PHP_VERSION, '8.1.0', '<')) { echo '  [ERROR] PHP 8.1+ needed, Laragon has '.PHP_VERSION.PHP_EOL; exit(1);} foreach (['pdo_mysql','mbstring','bcmath','openssl'] as $e) { if (extension_loaded($e) === false) { echo '  [ERROR] PHP extension missing: '.$e.' (Laragon menu: PHP - Extensions)'.PHP_EOL; exit(1);} } echo '  PHP '.PHP_VERSION.' OK'.PHP_EOL;"
if errorlevel 1 goto :fail

echo.
echo [2/7] Checking the database server ...
"%MYSQLBIN%\mysqladmin.exe" -u root -h 127.0.0.1 --port=3306 ping >nul 2>&1
if errorlevel 1 ( echo   [ERROR] MySQL is not running. Open Laragon and press "Start All", then run this again. & goto :fail )
echo   MySQL is running.

echo.
echo [3/7] Creating the CRM database and settings ...
if not exist "%ROOT%\.env" (
    for /f "usebackq delims=" %%P in (`powershell -NoProfile -Command "-join ((48..57)+(65..90)+(97..122) | Get-Random -Count 24 | ForEach-Object {[char]$_})"`) do set "DBPASS=%%P"
    for /f "usebackq delims=" %%P in (`powershell -NoProfile -Command "-join ((48..57)+(97..122) | Get-Random -Count 32 | ForEach-Object {[char]$_})"`) do set "MIGTOKEN=%%P"
    "%MYSQLBIN%\mysql.exe" -u root -h 127.0.0.1 --port=3306 -e "CREATE DATABASE IF NOT EXISTS mirsys_crm CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci; CREATE USER IF NOT EXISTS 'mirsys'@'localhost' IDENTIFIED BY '!DBPASS!'; CREATE USER IF NOT EXISTS 'mirsys'@'127.0.0.1' IDENTIFIED BY '!DBPASS!'; GRANT ALL ON mirsys_crm.* TO 'mirsys'@'localhost'; GRANT ALL ON mirsys_crm.* TO 'mirsys'@'127.0.0.1'; FLUSH PRIVILEGES;"
    if errorlevel 1 ( echo   [ERROR] Could not create the database ^(is the MySQL root password empty, as in a default Laragon?^). & goto :fail )
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
        echo DB_PORT=3306
        echo DB_DATABASE=mirsys_crm
        echo DB_USERNAME=mirsys
        echo DB_PASSWORD=!DBPASS!
        echo SESSION_SECURE_COOKIE=false
        echo SESSION_SAME_SITE=Lax
        echo AUTH_REQUIRE_EMAIL_VERIFICATION=false
        echo MAIL_DRIVER=log
        echo MIGRATE_TOKEN=!MIGTOKEN!
    )
    echo   .env created ^(database mirsys_crm, random password^).
) else (
    echo   .env already exists - kept.
)

echo.
echo [4/7] Creating / updating the tables ...
"%PHP%" "%ROOT%\windows-offline\setup.php" migrate
if errorlevel 1 ( echo   [ERROR] Migration failed. & goto :fail )

echo.
echo [5/7] Apache site on port %WEBPORT% (license gate in front of the CRM) ...
set "SITE=%LARAGON%\etc\apache2\sites-enabled\mirsys-crm.conf"
REM Delayed expansion off while writing: the Apache rules contain "!".
setlocal DisableDelayedExpansion
> "%SITE%" (
    echo # Mirsys CRM - written by windows-laragon\install.bat. Every request that is not an
    echo # existing static file goes through the license gate ^(windows-offline/router.php^),
    echo # which then runs the CRM ^(public/index.php^).
    echo Listen %WEBPORT%
    echo ^<VirtualHost *:%WEBPORT%^>
    echo     DocumentRoot "%ROOTFWD%/public"
    echo     Alias /__mirsys_gate "%ROOTFWD%/windows-offline/router.php"
    echo     ^<Directory "%ROOTFWD%/public"^>
    echo         AllowOverride None
    echo         Require all granted
    echo         Options -Indexes
    echo     ^</Directory^>
    echo     ^<Directory "%ROOTFWD%/windows-offline"^>
    echo         ^<Files "router.php"^>
    echo             Require all granted
    echo         ^</Files^>
    echo     ^</Directory^>
    echo     RewriteEngine On
    echo     RewriteCond %%{REQUEST_URI} !^^/__mirsys_gate
    echo     RewriteCond %%{DOCUMENT_ROOT}%%{REQUEST_URI} !-f [OR]
    echo     RewriteCond %%{REQUEST_URI} \.php$ [NC]
    echo     RewriteRule ^^ /__mirsys_gate [PT,L]
    echo ^</VirtualHost^>
)
endlocal
echo   Written: %SITE%

echo.
echo [6/7] Network access and background jobs ...
netsh advfirewall firewall show rule name="Mirsys CRM %WEBPORT%" >nul 2>&1
if errorlevel 1 (
    netsh advfirewall firewall add rule name="Mirsys CRM %WEBPORT%" dir=in action=allow protocol=TCP localport=%WEBPORT% profile=private,domain >nul 2>&1
    if errorlevel 1 ( echo   [WARN] Firewall rule not added - run as administrator. ) else ( echo   Firewall rule added ^(TCP %WEBPORT%^). )
) else (
    echo   Firewall rule already exists.
)
schtasks /create /f /sc minute /mo 1 /ru SYSTEM /tn "MirsysCRM-Cron" /tr "\"%WL%cron.bat\" \"%LARAGON%\"" >nul 2>&1
if errorlevel 1 ( echo   [WARN] Background jobs not scheduled - run as administrator. ) else ( echo   Background jobs scheduled every minute ^(task MirsysCRM-Cron^). )

echo.
echo [7/7] Done.
echo ==========================================================
echo   1. In Laragon: Menu - Apache - Reload  (or Stop / Start All)
echo   2. Open http://localhost:%WEBPORT%  - it shows the INSTALL CODE.
echo      Send it to the vendor and install the license file on that page.
echo   3. Register the first account, then run:
echo         windows-laragon\make-superadmin.bat your@email
echo   Other computers: http://THIS-COMPUTER-IP:%WEBPORT%
echo ==========================================================
echo.
pause
exit /b 0

:fail
echo.
echo Installation stopped.
pause
exit /b 1
