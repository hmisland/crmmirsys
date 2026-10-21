@echo off
REM Background jobs, every minute (Windows Task Scheduler task "MirsysCRM-Cron", created by install.bat):
REM shared scheduler (follow-up reminders, project delay alerts, CX, WooCommerce), task reminders,
REM marketing queue. Each script has its own lock, so overlapping runs are safe.
setlocal EnableExtensions
for %%I in ("%~dp0..") do set "ROOT=%%~fI"
set "PHP=%ROOT%\runtime\php\php.exe"
set "PHPINI=%ROOT%\runtime\php\php.ini"
"%ROOT%\runtime\mariadb\bin\mysqladmin.exe" -u root --port=3307 -h 127.0.0.1 ping >nul 2>&1 || exit /b 0
start "" /b "%PHP%" -c "%PHPINI%" "%ROOT%\app\bin\scheduler.php"
start "" /b "%PHP%" -c "%PHPINI%" "%ROOT%\modules\Sales\bin\task-reminders.php"
"%PHP%" -c "%PHPINI%" "%ROOT%\modules\Marketing\bin\worker.php"
