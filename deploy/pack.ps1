# A-Blog 全家桶打包（等价 package-plugin.bat，供命令行/CI 使用）
$ErrorActionPreference = 'Stop'
$root = 'E:\my-project\A-Blog'
$ver = '1.5.24'
$tmp  = Join-Path $root 'dist\_pkg_tmp'
$dst  = Join-Path $tmp 'ai-auto-blog-publish'
$out  = Join-Path $root ("dist\ai-auto-blog-publish-v{0}.zip" -f $ver)

Remove-Item $tmp -Recurse -Force -ErrorAction SilentlyContinue
New-Item -ItemType Directory -Path $dst -Force | Out-Null

# 1. WP plugin (PHP)
Copy-Item (Join-Path $root 'wp-plugin\*') (Join-Path $dst '\') -Recurse -Force
Write-Host '[1/5] WP plugin copied'

# 2. Python backend（排除密钥/缓存/运行时配置）
robocopy (Join-Path $root 'backend') (Join-Path $dst 'backend') /E /XD __pycache__ data /XF *.pyc config.yaml | Out-Null
if ($LASTEXITCODE -ge 8) { throw 'backend robocopy failed' }
Write-Host '[2/5] backend copied (secrets excluded)'

# 3. deploy 脚本进 backend\
$scripts = 'install-backend.bat','start-backend.bat','install.sh','ablog.service','security.md','backend-README.md'
foreach ($s in $scripts) {
    $src = Join-Path $root ('deploy\' + $s)
    $destName = if ($s -eq 'backend-README.md') { 'README.md' } else { $s }
    Copy-Item $src (Join-Path $dst ('backend\' + $destName)) -Force
}
Write-Host '[3/5] deploy scripts bundled'

# 4. backend\data 模板
New-Item -ItemType Directory -Path (Join-Path $dst 'backend\data') -Force | Out-Null
@'
A-Blog 伴生服务数据目录（安装后生成，勿提交到版本库）:
  ablog.db         SQLite 数据库（任务/选题/指纹）
  wp_token.txt     WordPress REST Bearer Token
  tavily_key.txt   Tavily 搜索 API Key
  sensitive_words.txt  敏感词表（缺失自动创建）
  logs\            运行日志
'@ | Set-Content (Join-Path $dst 'backend\data\README.txt') -Encoding UTF8
Write-Host '[4/5] data template ready'

# 5. zip
Remove-Item $out -Force -ErrorAction SilentlyContinue
Add-Type -AssemblyName System.IO.Compression.FileSystem
[System.IO.Compression.ZipFile]::CreateFromDirectory($tmp, $out)
Remove-Item $tmp -Recurse -Force
Write-Host ('[5/5] output: ' + $out)

