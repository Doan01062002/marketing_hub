@echo off
REM Chạy mỗi 5 phút bằng Task Scheduler. Ghi kết quả vào data\cron.log
cd /d "%~dp0.."
"%LOCALAPPDATA%\Microsoft\WinGet\Packages\PHP.PHP.8.3_Microsoft.Winget.Source_8wekyb3d8bbwe\php.exe" cron\run.php >> data\cron.log 2>&1
