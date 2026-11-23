@echo off
setlocal
powershell.exe -NoLogo -NoProfile -ExecutionPolicy Bypass -File "%~dp0run_windows.ps1"
if errorlevel 1 (
  echo.
  echo Ledger did not start. Review the error details above.
)
echo.
echo Press any key to close this window.
pause >nul
endlocal
