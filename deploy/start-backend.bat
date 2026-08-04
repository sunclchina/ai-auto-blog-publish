@echo off
rem A-Blog 伴生服务一键启动（127.0.0.1:8080）
rem 用法：双击运行，或命令行执行本脚本
cd /d "%~dp0..\backend"
echo [A-Blog] 启动伴生服务 http://127.0.0.1:8080 ...
echo [A-Blog] 健康检查: http://127.0.0.1:8080/healthz
python -m uvicorn main:app --host 127.0.0.1 --port 8080
pause
