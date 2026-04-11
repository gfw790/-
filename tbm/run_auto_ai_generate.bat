@echo off
setlocal
cd /d "%~dp0"
A:\risk_server\xampp\php\php.exe auto_ai_generate.php %*
