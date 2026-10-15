@echo off
setlocal
title CloudInterweb Notification Scheduler Setup
powershell.exe -NoProfile -ExecutionPolicy Bypass -File "%~dp0scripts\install_notification_task.ps1"
set "setup_result=%errorlevel%"
echo.
pause
exit /b %setup_result%
