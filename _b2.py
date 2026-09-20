import io
p1 = r'D:\my-project\A-Blog\ai-auto-blog-publish.php'
c = io.open(p1, encoding='utf-8').read()
c = c.replace("* Version: 1.5.61", "* Version: 1.5.62")
c = c.replace("define( 'ABP_VERSION', '1.5.61' );", "define( 'ABP_VERSION', '1.5.62' );")
io.open(p1, 'w', encoding='utf-8', newline='').write(c)

p2 = r'D:\my-project\A-Blog\readme.txt'
r = io.open(p2, encoding='utf-8').read()
r = r.replace("Stable tag: 1.5.61", "Stable tag: 1.5.62")
# 删掉 1.5.61 那条"自动部署"changelog，换成 1.5.62 说明
import re
r = re.sub(r"= 1\.5\.61 =\n.*?\n\n", "", r, flags=re.S)
marker = "= 1.5.60 ="
new = """= 1.5.62 =
* 仅修 bug：数据闸放宽成交额、API Token 重新生成写不进、market 历史补写统一数据源。
  无任何新增部署步骤。

"""
r = r.replace(marker, new + marker)
io.open(p2, 'w', encoding='utf-8', newline='').write(r)
print("OK")
