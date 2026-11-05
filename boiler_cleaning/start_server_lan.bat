@echo off
cd /d "%~dp0"
where py >nul 2>nul
if %errorlevel%==0 (
  py -3 app.py --host 0.0.0.0 --port 8080
) else (
  python app.py --host 0.0.0.0 --port 8080
)
pause
