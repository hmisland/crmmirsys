@echo off
setlocal EnableExtensions
REM Makes a registered user Super Admin and enables every module for their organization.
for %%I in ("%~dp0..") do set "ROOT=%%~fI"
set "EMAIL=%~1"
if "%EMAIL%"=="" set /p EMAIL="Email of the account to make Super Admin: "
call "%~dp0start-db.bat" >nul
"%ROOT%\runtime\php\php.exe" -c "%ROOT%\runtime\php\php.ini" "%~dp0setup.php" superadmin "%EMAIL%"
pause
