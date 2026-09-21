import io
p1 = r'D:\my-project\A-Blog\ai-auto-blog-publish.php'
c = io.open(p1, encoding='utf-8').read()
c = c.replace("* Version: 1.5.63", "* Version: 1.5.64")
c = c.replace("define( 'ABP_VERSION', '1.5.63' );", "define( 'ABP_VERSION', '1.5.64' );")
io.open(p1, 'w', encoding='utf-8', newline='').write(c)

p2 = r'D:\my-project\A-Blog\readme.txt'
r = io.open(p2, encoding='utf-8').read()
r = r.replace("Stable tag: 1.5.63", "Stable tag: 1.5.64")
marker = "= 1.5.63 ="
newblock = """= 1.5.64 =
* 修在线升级「无法安装这个包」根因：加 upgrader_pre_install 钩子，装新包前先把旧插件目录
  删除或改名 .bak 备份，腾出目标路径；WP move 到空路径不再撞旧目录里删不掉的文件。

"""
r = r.replace(marker, newblock + marker)
io.open(p2, 'w', encoding='utf-8', newline='').write(r)
print("OK")
