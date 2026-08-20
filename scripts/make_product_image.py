#!/usr/bin/env python3
# kappstore用の商品画像。職の画面（職務・職責・目標と達成率）を主役にする。
# 実行: python3 scripts/make_product_image.py
from PIL import Image, ImageDraw, ImageFont
import os

HERE = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
SHOT = os.path.join(HERE, "outputs/khp_post.png")
OUT = os.path.join(HERE, "outputs/khrpost_product.png")

W, H = 1200, 675
BLACK = "/usr/share/fonts/opentype/noto/NotoSansCJK-Black.ttc"
BOLD = "/usr/share/fonts/opentype/noto/NotoSansCJK-Bold.ttc"
MED = "/usr/share/fonts/opentype/noto/NotoSansCJK-Medium.ttc"

img = Image.new("RGB", (W, H), "#0e3742")
d = ImageDraw.Draw(img)

# 上半分は濃色、下半分は明色。境目に画面を跨がせて奥行きを出す
d.rectangle([0, 300, W, H], fill="#eef4f6")

f_title = ImageFont.truetype(BLACK, 50)
f_name = ImageFont.truetype(BLACK, 27)
f_sub = ImageFont.truetype(MED, 21)
f_tag = ImageFont.truetype(BOLD, 18)

d.text((64, 56), "職務・目標・引継ぎ管理", font=f_title, fill="#ffffff")
d.text((64, 126), "Kurage HR Post", font=f_name, fill="#5fd0e0")
d.text((64, 178), "担当者が代わっても、その職が何をする職かは残る。", font=f_sub, fill="#bfd8de")
d.text((64, 212), "前任の記録を見ながら、コピーして書ける。", font=f_sub, fill="#bfd8de")

# 右上のタグ
tags = ["1ファイルPHP", "DBサーバー不要", "買い切り"]
x = W - 64
for t in reversed(tags):
    tw = d.textlength(t, font=f_tag)
    d.rounded_rectangle([x - tw - 28, 62, x, 102], radius=20, outline="#5fd0e0", width=2)
    d.text((x - tw - 14, 71), t, font=f_tag, fill="#5fd0e0")
    x -= tw + 40

# 画面（職務・職責・目標の表まで）を切り出して中央に置く
shot = Image.open(SHOT).convert("RGB").crop((196, 62, 1230, 460))
sw = 940
shot = shot.resize((sw, int(shot.height * sw / shot.width)), Image.LANCZOS)
sx, sy = (W - sw) // 2, 252
d.rectangle([sx - 6, sy - 6, sx + sw + 6, sy + shot.height + 6], fill="#ffffff")
d.rectangle([sx - 7, sy - 7, sx + sw + 7, sy + shot.height + 7], outline="#c9dade", width=1)
img.paste(shot, (sx, sy))

# 下の帯
f_foot = ImageFont.truetype(BOLD, 19)
foot = "職 → 職務・職責・目標 → タスク。達成率はコードが計算し、AIに採点させない。"
d.text((64, H - 52), foot, font=f_foot, fill="#3d5560")
price = "買い切り 55,000円（税込）"
pw = d.textlength(price, font=f_foot)
d.text((W - 64 - pw, H - 52), price, font=f_foot, fill="#0b7f95")

img.save(OUT)
print("saved:", OUT, img.size)
