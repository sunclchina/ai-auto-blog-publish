@echo off
rem ============================================================
rem  A-Blog Python 伴生服务 - Windows 一键安装脚本
rem  前置要求: 已安装 Python 3.10+（含 pip）
rem  用法:     双击运行，或命令行执行本脚本
rem  完成后:   运行 start-backend.bat 启动服务（127.0.0.1:8080）
rem ============================================================
setlocal
cd /d "%~dp0"

echo [A-Blog] ============================================
echo [A-Blog]  伴生服务安装（backend 目录）
echo [A-Blog] ============================================

rem ---- 1. Python 检查 ----
python --version >nul 2>&1
if errorlevel 1 (
  echo [A-Blog] ERROR: 未检测到 Python，请先安装 Python 3.10+ 并勾选 "Add to PATH"。
  echo [A-Blog] 下载: https://www.python.org/downloads/
  pause
  exit /b 1
)

rem ---- 2. 数据目录（密钥/数据库放这里，勿提交到版本库）----
if not exist data mkdir data
echo [A-Blog] 数据目录就绪: %cd%\data

rem ---- 3. 配置文件（首次复制模板）----
if not exist config.yaml (
  copy config.yaml.example config.yaml >nul
  echo [A-Blog] 已生成 config.yaml（模板），请按需编辑密钥等配置项。
) else (
  echo [A-Blog] config.yaml 已存在，跳过。
)

rem ---- 4. 安装依赖 ----
echo [A-Blog] 安装 Python 依赖（fastapi/uvicorn/httpx 等）...
python -m pip install -r requirements.txt
if errorlevel 1 (
  echo [A-Blog] ERROR: 依赖安装失败，请检查网络或 pip 配置。
  pause
  exit /b 1
)

rem ---- 5. 完成提示 ----
echo.
echo [A-Blog] ============================================
echo [A-Blog]  安装完成！
echo [A-Blog]  下一步: 运行 start-backend.bat 启动服务
echo [A-Blog]  健康检查: http://127.0.0.1:8080/healthz
echo [A-Blog]  密钥配置: 编辑 config.yaml（API Key / WP Token 等）
echo [A-Blog] ============================================
pause
