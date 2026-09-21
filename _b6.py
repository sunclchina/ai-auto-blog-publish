import io
p1 = r'D:\my-project\A-Blog\ai-auto-blog-publish.php'
c = io.open(p1, encoding='utf-8').read()
c = c.replace("* Version: 1.5.65", "* Version: 1.5.66")
c = c.replace("define( 'ABP_VERSION', '1.5.65' );", "define( 'ABP_VERSION', '1.5.66' );")
io.open(p1, 'w', encoding='utf-8', newline='').write(c)

p2 = r'D:\my-project\A-Blog\readme.txt'
r = io.open(p2, encoding='utf-8').read()
r = r.replace("Stable tag: 1.5.65", "Stable tag: 1.5.66")
marker = "= 1.5.65 ="
newblock = """= 1.5.66 =
* 修 pre_install 钩子签名：upgrader_pre_install 是 do_action 只传 1 个参数，
  之前写成 2 个参数导致 hook_extra 错位、钩子实际没执行。

"""
r = r.replace(marker, newblock + marker)
io.open(p2, 'w', encoding='utf-8', newline='').write(r)
print("OK")
