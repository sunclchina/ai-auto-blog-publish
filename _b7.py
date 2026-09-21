import io
p1 = r'D:\my-project\A-Blog\ai-auto-blog-publish.php'
c = io.open(p1, encoding='utf-8').read()
c = c.replace("* Version: 1.5.66", "* Version: 1.5.67")
c = c.replace("define( 'ABP_VERSION', '1.5.66' );", "define( 'ABP_VERSION', '1.5.67' );")
io.open(p1, 'w', encoding='utf-8', newline='').write(c)

p2 = r'D:\my-project\A-Blog\readme.txt'
r = io.open(p2, encoding='utf-8').read()
r = r.replace("Stable tag: 1.5.66", "Stable tag: 1.5.67")
marker = "= 1.5.66 ="
newblock = """= 1.5.67 =
* 撤掉 pre_install 钩子（和 WP 6.x 自带的 upgrade-temp-backup 备份机制冲突），
  回到 WP 默认升级流程。

"""
r = r.replace(marker, newblock + marker)
io.open(p2, 'w', encoding='utf-8', newline='').write(r)
print("OK")
