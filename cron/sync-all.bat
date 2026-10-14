@echo off
rem Dong bo so lieu tat ca kenh — dat lich chay hang ngay bang Windows Task Scheduler.
rem Ket qua ghi them vao data\sync.log
chcp 65001 >nul
set "PHP=%LOCALAPPDATA%\Microsoft\WinGet\Packages\PHP.PHP.8.3_Microsoft.Winget.Source_8wekyb3d8bbwe\php.exe"
cd /d "%~dp0.."
"%PHP%" "%~dp0sync-all.php" >> "%~dp0..\data\sync.log" 2>&1
exit /b %ERRORLEVEL%
