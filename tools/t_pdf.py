import sys, json, time, copy; sys.path.insert(0,'/home/claude/tp')
from lib import *
from playwright.sync_api import sync_playwright
D='/home/claude/vid3/data/'
cases = [('normal-1page', D+'seraphine-vale-n.json', {'damageSheet': False}),
         ('normal-dmg-2pages', D+'thessaly-brightwater-n.json', {}),
         ('twopage-dmg-3pages', D+'grimgar-ashfang-tp.json', {}),
         ('twopage-inksaver', D+'the-whispering-shade-tp.json', {'ink': True, 'damageSheet': False})]
with sync_playwright() as p:
    b=p.chromium.launch(executable_path='/usr/bin/google-chrome')
    ctx=b.new_context(viewport={"width":1500,"height":1100}, accept_downloads=True)
    pg=ctx.new_page(); errs=[]; pg.on('pageerror', lambda e: errs.append(str(e)))
    pg.goto('http://127.0.0.1:8088/BB/'); pg.wait_for_timeout(600)
    for name, f, opt in cases:
        d=json.load(open(f))
        if opt.get('damageSheet') is False: d['character']['damageSheet']['show']=False
        json.dump(d, open('/tmp/in.json','w')); pg.set_input_files('#import-file','/tmp/in.json'); pg.wait_for_timeout(1000)
        if pg.is_checked('#ink-saver') != bool(opt.get('ink')): pg.evaluate("(()=>{const c=document.querySelector('#ink-saver'); c.checked=!c.checked; c.dispatchEvent(new Event('change',{bubbles:true}))})()"); pg.wait_for_timeout(300)
        t0=time.time()
        with pg.expect_download(timeout=60000) as dl: pg.click('[data-action=pdf]')
        dl.value.save_as(f'{name}.pdf'); print(name, dl.value.suggested_filename, f'{time.time()-t0:.1f}s', pg.text_content('#toast')[:110])
        for i, sel in enumerate([s for s in ('#sheet','#sheet-p2','#sheet-dmg') if pg.evaluate(f"!document.querySelector('{s}').hidden")]):
            pg.locator(sel).screenshot(path=f'{name}-preview-{i+1}.png')
    print('errors', errs); b.close()
