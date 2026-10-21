@echo off
REM Background jobs every minute (Task Scheduler "MirsysCRM-Cron"), only while MySQL runs and the license is valid.
setlocal EnableExtensions
for %%I in ("%~dp0..") do set "ROOT=%%~fI"
call "%~dp0find-laragon.bat" %1
if "%PHP%"=="" exit /b 0
"%MYSQLBIN%\mysqladmin.exe" -u root -h 127.0.0.1 --port=3306 ping >nul 2>&1 || exit /b 0
"%PHP%" "%ROOT%\windows-offline\setup.php" license-ok >nul 2>&1 || exit /b 0
start "" /b "%PHP%" "%ROOT%\app\bin\scheduler.php"
start "" /b "%PHP%" "%ROOT%\modules\Sales\bin\task-reminders.php"
"%PHP%" "%ROOT%\modules\Marketing\bin\worker.php"
