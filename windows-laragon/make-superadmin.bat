@echo off
setlocal EnableExtensions
REM Makes a registered user Super Admin and enables every module for their organization.
REM Usage: make-superadmin.bat your@email [Laragon folder]
for %%I in ("%~dp0..") do set "ROOT=%%~fI"
call "%~dp0find-laragon.bat" %2
set "EMAIL=%~1"
if "%EMAIL%"=="" set /p EMAIL="Email of the account to make Super Admin: "
"%PHP%" "%ROOT%\windows-offline\setup.php" superadmin "%EMAIL%"
pause
