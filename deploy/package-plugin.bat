@echo off
rem ============================================================
rem  A-Blog plugin one-click package script (Windows)
rem  Usage: double-click -> dist\ai-auto-blog-publish-v<ver>.zip
rem  Version is auto-read from wp-plugin\ai-auto-blog-publish.php
rem  Publish: create a GitHub Release tagged v<ver> and upload
rem  the generated zip (updater prefers release assets).
rem ============================================================
setlocal
cd /d "%~dp0.."

rem ---- read plugin version (ABP_VERSION const, 4th single-quoted field) ----
for /f "tokens=4 delims='" %%v in ('findstr /c:"ABP_VERSION" wp-plugin\ai-auto-blog-publish.php') do set VER=%%v
if "%VER%"=="" (
  echo [A-Blog] ERROR: cannot read ABP_VERSION, check the plugin main file.
  pause
  exit /b 1
)
echo [A-Blog] plugin version: %VER%

rem ---- package (zip root dir = ai-auto-blog-publish) ----
powershell -NoProfile -ExecutionPolicy Bypass -Command ^
  "$tmp = Join-Path $PWD 'dist\_pkg_tmp'; Remove-Item $tmp -Recurse -Force -ErrorAction SilentlyContinue;" ^
  "New-Item -ItemType Directory -Path (Join-Path $tmp 'ai-auto-blog-publish') -Force | Out-Null;" ^
  "Copy-Item (Join-Path $PWD 'wp-plugin\*') (Join-Path $tmp 'ai-auto-blog-publish\') -Recurse -Force;" ^
  "$out = Join-Path $PWD ('dist\ai-auto-blog-publish-v%VER%.zip');" ^
  "Remove-Item $out -Force -ErrorAction SilentlyContinue;" ^
  "Add-Type -AssemblyName System.IO.Compression.FileSystem;" ^
  "[System.IO.Compression.ZipFile]::CreateFromDirectory($tmp, $out);" ^
  "Remove-Item $tmp -Recurse -Force;" ^
  "Write-Host ('[A-Blog] output: ' + $out)"

if exist "dist\ai-auto-blog-publish-v%VER%.zip" (
  echo [A-Blog] package done.
) else (
  echo [A-Blog] ERROR: packaging failed, check dist dir.
)
pause
