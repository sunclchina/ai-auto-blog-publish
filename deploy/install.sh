#!/usr/bin/env bash
# ============================================================================
# A-Blog 伴生服务 · Linux 一键安装脚本
# 用法:  sudo bash install.sh [/opt/ablog]
#        (参数为安装目录, 默认 /opt/ablog; 与 crontab.txt / ablog.service 的路径约定一致)
# 功能:  ① 创建专用系统用户 ablog(nologin)
#        ② 创建 Python venv 并安装 backend/requirements.txt
#        ③ 初始化 data/ 目录 (logs/ images/ sensitive_words.txt)
#        ④ config.yaml.example → config.yaml (已存在则不覆盖)
#        ⑤ deploy/.env.example → backend/.env 并 chmod 600 (密钥注入文件)
#        ⑥ 安装 systemd 单元 ablog.service (仅安装, 不自动启动)
# 幂等:  可重复执行, 已存在的文件/用户/venv 均跳过, 不破坏现有配置。
# ============================================================================
set -euo pipefail

ABLOG_HOME="${1:-/opt/ablog}"
SERVICE_USER="${ABLOG_SERVICE_USER:-ablog}"
BACKEND="${ABLOG_HOME}/backend"
VENV="${BACKEND}/venv"
DATA="${ABLOG_HOME}/data"

# 脚本所在目录(仓库 deploy/), 用于定位源码 backend/ 与模板文件
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO_BACKEND="${SCRIPT_DIR}/../backend"

log()  { echo -e "[install] $*"; }
fail() { echo -e "[ERROR] $*" >&2; exit 1; }

# ---- 0) 前置检查 ----------------------------------------------------------
command -v python3 >/dev/null 2>&1 || fail "未找到 python3, 请先安装 (Ubuntu: apt install -y python3 python3-venv python3-pip)"
python3 -c 'import venv' >/dev/null 2>&1 || fail "python3-venv 未安装 (Ubuntu: apt install -y python3-venv)"
command -v curl >/dev/null 2>&1 || log "提示: 未找到 curl(仅验证用), 建议 apt install -y curl"

# ---- 1) 目录初始化 --------------------------------------------------------
mkdir -p "${BACKEND}" "${DATA}/logs" "${DATA}/images" "${ABLOG_HOME}/backup"
touch "${DATA}/sensitive_words.txt"            # 敏感词表(每行一词, 可编辑)
log "目录就绪: ${ABLOG_HOME} (data/logs, data/images, backup)"

# ---- 2) 专用系统用户(免密, 不可登录) --------------------------------------
if ! id "${SERVICE_USER}" >/dev/null 2>&1; then
  NOLOGIN="$(command -v nologin || echo /usr/sbin/nologin)"
  useradd --system --home "${ABLOG_HOME}" --shell "${NOLOGIN}" "${SERVICE_USER}"
  log "已创建系统用户 ${SERVICE_USER} (nologin)"
else
  log "系统用户 ${SERVICE_USER} 已存在, 跳过"
fi

# ---- 3) 后端代码(安装目录缺失时从仓库复制) --------------------------------
if [ ! -f "${BACKEND}/requirements.txt" ]; then
  if [ -f "${REPO_BACKEND}/requirements.txt" ]; then
    cp -r "${REPO_BACKEND}/." "${BACKEND}/"
    log "已从仓库复制 backend 代码 → ${BACKEND}"
  else
    fail "找不到 backend/requirements.txt (安装目录与仓库 deploy/../backend 均无, 请先上传代码)"
  fi
else
  log "backend 代码已存在, 跳过复制"
fi

# ---- 4) 配置文件初始化(不覆盖已有配置) ------------------------------------
if [ ! -f "${BACKEND}/config.yaml" ]; then
  cp "${BACKEND}/config.yaml.example" "${BACKEND}/config.yaml"
  log "已生成 ${BACKEND}/config.yaml, 请按需修改(栏目开关/额度/WP 地址等)"
else
  log "config.yaml 已存在, 跳过"
fi

if [ ! -f "${BACKEND}/.env" ]; then
  cp "${SCRIPT_DIR}/.env.example" "${BACKEND}/.env"
  log "已生成 ${BACKEND}/.env, 请填入真实密钥(见 deploy/security.md)"
else
  log ".env 已存在, 跳过"
fi
chmod 600 "${BACKEND}/config.yaml" "${BACKEND}/.env"
chmod 700 "${DATA}" "${ABLOG_HOME}/backup"
log "权限: config.yaml / .env = 600, data / backup = 700"

# ---- 5) Python venv + 依赖 -------------------------------------------------
if [ ! -d "${VENV}" ]; then
  python3 -m venv "${VENV}"
  log "已创建 venv: ${VENV}"
fi
"${VENV}/bin/pip" install --upgrade pip
"${VENV}/bin/pip" install -r "${BACKEND}/requirements.txt"
log "依赖安装完成"

# ---- 6) 依赖自检 ------------------------------------------------------------
"${VENV}/bin/python" - <<'PY'
import importlib
mods = ["fastapi", "uvicorn", "yaml", "httpx", "PIL", "requests", "pytest"]
missing = [m for m in mods if importlib.util.find_spec(m) is None]
if missing:
    raise SystemExit(f"[ERROR] 依赖缺失: {missing}")
print("[install] 依赖自检通过:", ", ".join(mods))
PY

# ---- 7) 属主与 systemd 单元 -------------------------------------------------
chown -R "${SERVICE_USER}:${SERVICE_USER}" "${ABLOG_HOME}" 2>/dev/null || true
log "属主已设为 ${SERVICE_USER}"

if [ -d /etc/systemd/system ] && [ -f "${SCRIPT_DIR}/ablog.service" ]; then
  sed "s|/opt/ablog|${ABLOG_HOME}|g" "${SCRIPT_DIR}/ablog.service" > /etc/systemd/system/ablog.service
  systemctl daemon-reload
  log "已安装 systemd 单元 ablog.service (路径已按 ${ABLOG_HOME} 替换; 未自动启动)"
else
  log "未检测到 systemd 或缺少 ablog.service, 跳过单元安装(可手工部署)"
fi

# ---- 8) 完成 ----------------------------------------------------------------
cat <<EOF

============================================================
 安装完成 ✔  安装目录: ${ABLOG_HOME}
 下一步 (详见 deploy/README.md):
   1. 编辑配置:   sudo nano ${BACKEND}/config.yaml
   2. 填入密钥:   sudo nano ${BACKEND}/.env
   3. 启动服务:   sudo systemctl enable --now ablog
   4. 验证健康:   curl http://127.0.0.1:8080/healthz
   5. 安装定时:   crontab -e  ← 粘贴 backend/crontab.txt 内容
============================================================
EOF
