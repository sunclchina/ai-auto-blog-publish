import io
p1 = r'D:\my-project\A-Blog\ai-auto-blog-publish.php'
c = io.open(p1, encoding='utf-8').read()
c = c.replace("* Version: 1.5.62", "* Version: 1.5.63")
c = c.replace("define( 'ABP_VERSION', '1.5.62' );", "define( 'ABP_VERSION', '1.5.63' );")
io.open(p1, 'w', encoding='utf-8', newline='').write(c)

p2 = r'D:\my-project\A-Blog\readme.txt'
r = io.open(p2, encoding='utf-8').read()
r = r.replace("Stable tag: 1.5.62", "Stable tag: 1.5.63")
marker = "= 1.5.62 ="
newblock = """= 1.5.63 =
* 修复在线升级「无法安装这个包」：fix_source_dir 删除旧插件目录失败时改名为 .bak-时间戳，
  腾出目标路径再移动新目录（旧目录里有文件被锁/权限不对时不再卡死）

"""
r = r.replace(marker, newblock + marker)
io.open(p2, 'w', encoding='utf-8', newline='').write(r)
print("OK")
