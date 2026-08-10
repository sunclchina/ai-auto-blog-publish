@echo off
rem ============================================================
rem  A-Blog plugin one-click package script (Windows)
rem  Usage: double-click -> dist\ai-auto-blog-publish-v<ver>.zip
rem  Version is auto-read from ai-auto-blog-publish.php
rem  The zip is a FULL BUNDLE: WP plugin (PHP) + Python backend.
rem  Secrets (data/ db & tokens, config.yaml) are EXCLUDED.
rem  Publish: create a GitHub Release tagged v<ver> and upload
rem  the generated zip (updater prefers release assets).
rem ============================================================
setlocal
cd /d "%~dp0.."

rem ---- read plugin version (ABP_VERSION const, 4th single-quoted field) ----
for /f "tokens=4 delims='" %%v in ('findstr /c:"ABP_VERSION" ai-auto-blog-publish.php') do set VER=%%v
if "%VER%"=="" (
  echo [A-Blog] ERROR: cannot read ABP_VERSION, check the plugin main file.
  pause
  exit /b 1
)
echo [A-Blog] plugin version: %VER%

set TMP=dist\_pkg_tmp
set DST=%TMP%\ai-auto-blog-publish
set OUT=dist\ai-auto-blog-publish-v%VER%.zip

rem ---- clean & init ----
powershell -NoProfile -ExecutionPolicy Bypass -Command "Remove-Item '%TMP%' -Recurse -Force -ErrorAction SilentlyContinue; New-Item -ItemType Directory -Path '%DST%' -Force | Out-Null"

rem ---- 1. WP plugin (PHP) ----
powershell -NoProfile -ExecutionPolicy Bypass -Command "Copy-Item 'ai-auto-blog-publish.php','readme.txt','uninstall.php' '%DST%\' -Force; Copy-Item 'includes','mu-plugins' '%DST%\' -Recurse -Force; New-Item -ItemType Directory -Path '%DST%\assets' -Force | Out-Null; Copy-Item 'assets\css','assets\js' '%DST%\assets\' -Recurse -Force"
echo [A-Blog] WP plugin copied.

rem ---- 2. Python backend (exclude secrets / caches / live config) ----
robocopy backend "%DST%\backend" /E /XD __pycache__ data /XF *.pyc config.yaml >nul
if errorlevel 8 (
  echo [A-Blog] ERROR: backend sync failed.
  pause
  exit /b 1
)
echo [A-Blog] backend copied (secrets excluded).

rem ---- 3. deploy scripts bundled into backend\ ----
copy deploy\install-backend.bat "%DST%\backend\" >nul
copy deploy\start-backend.bat   "%DST%\backend\" >nul
copy deploy\install.sh          "%DST%\backend\" >nul
copy deploy\ablog.service       "%DST%\backend\" >nul
copy deploy\security.md         "%DST%\backend\" >nul
copy deploy\backend-README.md   "%DST%\backend\README.md" >nul
echo [A-Blog] deploy scripts bundled.

rem ---- 4. backend data template (real data created on install) ----
mkdir "%DST%\backend\data" >nul 2>&1
(
  echo A-Blog ???????????????????????:
  echo   ablog.db         SQLite ??????/??/???
  echo   wp_token.txt     WordPress REST Bearer Token
  echo   tavily_key.txt   Tavily ?? API Key
  echo   sensitive_words.txt  ????????????
  echo   logs\            ????
) > "%DST%\backend\data\README.txt"
echo [A-Blog] data template ready.

rem ---- 5. package (entries use forward slashes, required by WordPress) ----
powershell -NoProfile -ExecutionPolicy Bypass -Command ^
  "Remove-Item '%OUT%' -Force -ErrorAction SilentlyContinue;" ^
  "Add-Type -AssemblyName System.IO.Compression;" ^
  "Add-Type -AssemblyName System.IO.Compression.FileSystem;" ^
  "$root = Join-Path $PWD 'dist\_pkg_tmp';" ^
  "$outAbs = Join-Path $PWD '%OUT%';" ^
  "$fs = [System.IO.File]::Open($outAbs, [System.IO.FileMode]::CreateNew);" ^
  "$zip = New-Object System.IO.Compression.ZipArchive($fs, [System.IO.Compression.ZipArchiveMode]::Create);" ^
  "Get-ChildItem $root -Recurse -File | ForEach-Object {" ^
  "  $rel = $_.FullName.Substring($root.Length + 1).Replace('\','/');" ^
  "  $entry = $zip.CreateEntry($rel, [System.IO.Compression.CompressionLevel]::Optimal);" ^
  "  $es = $entry.Open();" ^
  "  $bytes = [System.IO.File]::ReadAllBytes($_.FullName);" ^
  "  $es.Write($bytes, 0, $bytes.Length);" ^
  "  $es.Close();" ^
  "};" ^
  "$zip.Dispose(); $fs.Close();" ^
  "Remove-Item $root -Recurse -Force;" ^
  "Write-Host ('[A-Blog] output: ' + $outAbs)"

if exist "%OUT%" (
  echo [A-Blog] package done.
) else (
  echo [A-Blog] ERROR: packaging failed, check dist dir.
)
pause
