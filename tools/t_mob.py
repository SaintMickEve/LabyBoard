from playwright.sync_api import sync_playwright
from lib import *
with sync_playwright() as p:
    b = p.chromium.launch(executable_path="/usr/bin/google-chrome", args=["--no-sandbox"])
    shots = []
    for w in (360, 390, 430):
        pg, errs = open_page(b, w=w, h=844, dsf=2, dialogs=[])
        pg.locator('.topbar').screenshot(path=f'mob{w}.png'); shots.append(f'mob{w}.png')
        r = pg.evaluate("(()=>{const t=document.querySelector('.fmt-toggle').getBoundingClientRect(), n=document.querySelector('[data-action=pdf]').getBoundingClientRect(); return [Math.round(t.top), Math.round(n.bottom), Math.round(t.width), Math.round(t.height)]})()")
        print(w, "toggle top / print bottom / toggle w,h:", r, errs or "")
        pg.close()
    dl=[]; pg, errs = open_page(b, w=1440, h=900, dsf=2, dialogs=dl)
    pg.locator('.actions').screenshot(path='deskbar.png')
    pass
    load(pg, normal_board('basic','default')); pg.click('button[data-format=twoPage]'); pg.wait_for_timeout(400)
    print("DIALOG:", dl[-1].splitlines()[0])
    b.close()
from PIL import Image
ims=[Image.open(f) for f in shots]; W=max(i.width for i in ims); H=sum(i.height for i in ims)+20*len(ims)
o=Image.new('RGB',(W,H),'white'); y=0
for i in ims: o.paste(i,(0,y)); y+=i.height+20
o.save('mob.png')
