import io
p1 = r'D:\my-project\A-Blog\ai-auto-blog-publish.php'
c = io.open(p1, encoding='utf-8').read()
c = c.replace("* Version: 1.5.64", "* Version: 1.5.65")
c = c.replace("define( 'ABP_VERSION', '1.5.64' );", "define( 'ABP_VERSION', '1.5.65' );")
io.open(p1, 'w', encoding='utf-8', newline='').write(c)

p2 = r'D:\my-project\A-Blog\readme.txt'
r = io.open(p2, encoding='utf-8').read()
r = r.replace("Stable tag: 1.5.64", "Stable tag: 1.5.65")
marker = "= 1.5.64 ="
newblock = """= 1.5.65 =
* 测试在线升级通道（pre_install 钩子备份旧目录）。

"""
r = r.replace(marker, newblock + marker)
io.open(p2, 'w', encoding='utf-8', newline='').write(r)
print("OK")
