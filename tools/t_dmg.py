"""Damage Sheet, Kit and Equipment page: imports with and without it on both boards, page numbers,
printed output (PDF page count and order), round trips, and the form editor."""
import json, subprocess, copy
from playwright.sync_api import sync_playwright
from lib import *
S = json.load(open('/home/claude/app/BB/config/schema.json')); D = S['damageSheet']
P = []
def ok(c, m):
    print(("ok   " if c else "FAIL ") + m)
    if not c: P.append(m)
def normal_char():
    return {"layout": "basic", "doll": "default", "theme": "blue", "watermark": "lightning", "name": "Test Hero", "alignment": "GN",
            "innates": [{"name": "Strike", "count": 2}], "consumables": [{"name": "Potion", "count": 1}]}
def with_dmg(c, show=True):
    c = copy.deepcopy(c)
    c["damageSheet"] = {"show": show, "corner": "Grade", "columns": ["Base", "Rage", "Miracle"],
                        "rows": [{"label": "Triple", "values": [3, 2, 1]}, {"label": "Quad", "values": [4, None, 2]}]}
    c["kit"] = [{"name": "Rope", "count": 1}, {"name": "Torch", "count": 3}]
    c["equipment"] = [{"name": "Sword", "count": None}]
    return c
def pdf_pages(pg):
    pg.emulate_media(media="print")
    pdf = pg.pdf(format="A4", print_background=True, margin={"top": "0", "bottom": "0", "left": "0", "right": "0"})
    pg.emulate_media(media="screen")
    open('/tmp/t_dmg.pdf', 'wb').write(pdf)
    n = int(subprocess.run(['pdfinfo', '/tmp/t_dmg.pdf'], capture_output=True, text=True).stdout.split('Pages:')[1].split()[0])
    texts = [subprocess.run(['pdftotext', '-f', str(i), '-l', str(i), '-layout', '/tmp/t_dmg.pdf', '-'], capture_output=True, text=True).stdout for i in range(1, n + 1)]
    return n, [''.join(t.upper().split()) for t in texts]
def check(pg, tag, tp, dmg):
    want = (2 if tp else 1) + (1 if dmg else 0)
    vis = pg.evaluate("[...document.querySelectorAll('.preview-scale .sheet')].filter(s=>!s.hidden).map(s=>s.id)")
    exp = ['sheet'] + (['sheet-p2'] if tp else []) + (['sheet-dmg'] if dmg else [])
    ok(vis == exp, f"{tag}: pages shown {vis}")
    nums = pg.evaluate("[...document.querySelectorAll('.preview-scale .sheet')].filter(s=>!s.hidden).map(s=>{const e=s.querySelector('.foot .pg');return e.hidden?'':e.textContent})")
    expn = [''] if want == 1 else [f"Page {i} of {want}" for i in range(1, want + 1)]
    ok(nums == expn, f"{tag}: page numbers {nums}")
    ok(pg.is_checked('[data-path="damageSheet.show"]') == dmg, f"{tag}: page tick box matches")
    ok(pg.evaluate("document.body.dataset.pages") == str(want), f"{tag}: preview sized for {want} page(s)")
    n, texts = pdf_pages(pg)
    ok(n == want, f"{tag}: PDF has {n} page(s), expected {want}")
    if dmg: ok('DAMAGESHEET' in texts[-1] and 'KITANDEQUIPMENT' in texts[-1], f"{tag}: Damage Sheet is the last printed page")
    if want > 1: ok(f"PAGE{want}OF{want}" in texts[-1], f"{tag}: last printed page says Page {want} of {want}")
    if tp: ok('IMMUNITIES' in texts[1] and 'PAGE2OF' in texts[1], f"{tag}: page 2 of the two-page board prints second")
    ok(not pg.evaluate(GEOM), f"{tag}: layout ok {pg.evaluate(GEOM)[:2]}")

with sync_playwright() as p:
    b = p.chromium.launch(executable_path="/usr/bin/google-chrome", args=["--no-sandbox"])
    dialogs = []
    pg, errs = open_page(b, dialogs=dialogs)
    cases = []
    tpc = full_tp()['character']
    for fmt, base in (("normal", normal_char()), ("twoPage", tpc)):
        for mode in ("absent", "off", "on"):
            c = copy.deepcopy(base)
            if mode != "absent": c = with_dmg(c, mode == "on")
            cases.append((fmt, mode, c))
    for fmt, mode, c in cases:
        tag = f"{fmt} board, page {mode}"
        load(pg, {"app": "battleboard", "version": 1, "character": c})
        st = state(pg)
        ok(st['format'] == fmt, f"{tag}: format {st['format']}")
        ds = st['damageSheet']
        if mode == "absent":
            ok(ds['show'] is False and ds['columns'] == D['columnsDefault'] and [r['label'] for r in ds['rows']] == D['rowsDefault'] and st['kit'] == [] and st['equipment'] == [], f"{tag}: defaults (off, example table)")
        else:
            ok(ds['columns'] == ["Base", "Rage", "Miracle"] and ds['rows'][1]['values'] == [4, None, 2] and st['kit'][1] == {"name": "Torch", "count": 3}, f"{tag}: table and kit imported")
        check(pg, tag, fmt == "twoPage", mode == "on")
        # export -> import gives the same board
        with pg.expect_download() as dl: pg.click('button[data-action=export]')
        exp = json.load(open(dl.value.path()))
        ok('damageSheet' in exp['character'] and 'kit' in exp['character'], f"{tag}: export records the page")
        load(pg, exp)
        ok(state(pg) == st, f"{tag}: export/import round trip unchanged")
    # an older file (no damageSheet/kit) imports with no warnings
    old = json.load(open('/mnt/user-data/uploads/3baaae79-winivan-paladni-battleboard.json'))
    load(pg, old) if False else None
    json.dump(old, open('/tmp/old.json', 'w')); pg.set_input_files('#import-file', '/tmp/old.json'); pg.wait_for_timeout(800)
    toast = pg.text_content('#toast') or ''
    ok('changed' not in toast.lower() and 'ignored' not in toast.lower(), f"older file imports cleanly: {toast[:90]!r}")
    ok(state(pg)['damageSheet']['show'] is False, "older file: page off")

    # switching format with the page on keeps it last and renumbers
    load(pg, {"app": "battleboard", "version": 1, "character": with_dmg(normal_char())})
    pg.click('button[data-format=twoPage]'); pg.wait_for_timeout(600)
    check(pg, "normal -> two-page with the page on", True, True)
    pg.click('button[data-format=normal]'); pg.wait_for_timeout(600)
    check(pg, "two-page -> normal with the page on", False, True)

    # form editor
    pg.close(); dialogs.clear()
    pg, errs2 = open_page(b, dialogs=dialogs)
    pg.click('details[data-sec=damageSheet] > summary')
    ok(not pg.is_visible('#dmg-grid'), "editor hidden while the page is off")
    pg.check('[data-path="damageSheet.show"]'); pg.wait_for_timeout(400)
    st = state(pg)
    ok(st['damageSheet']['show'] and len(st['kit']) == 10 and len(st['equipment']) == 10, "switching on: 10 blank kit and equipment rows")
    check(pg, "new board + page", False, True)
    pg.click('[data-dmg=addCol]')
    ok(pg.is_disabled('[data-dmg=addCol]') and len(state(pg)['damageSheet']['columns']) == 8, "8 columns max, button disabled")
    pg.fill('#dmg-grid tr:nth-child(2) td.val:nth-child(2) input', '42'); pg.wait_for_timeout(300)
    ok(state(pg)['damageSheet']['rows'][0]['values'][0] == 42, "value typed")
    ok(pg.text_content('#sheet-dmg-table .v') == '42', "value shown on the sheet")
    pg.fill('#dmg-grid tr:nth-child(2) td.val:nth-child(3) input', '123'); pg.wait_for_timeout(300)
    v = pg.input_value('#dmg-grid tr:nth-child(2) td.val:nth-child(3) input')
    ok(v == '12' and state(pg)['damageSheet']['rows'][0]['values'][1] == 12, f"values limited to 2 digits ({v})")
    pg.fill('#dmg-grid tr:nth-child(2) td.val:nth-child(3) input', 'ab'); pg.wait_for_timeout(300)
    ok(state(pg)['damageSheet']['rows'][0]['values'][1] is None, "letters ignored")
    pg.fill('#dmg-grid tr.h th:nth-child(9) textarea', 'X' * 50); pg.wait_for_timeout(200)
    ok(len(state(pg)['damageSheet']['columns'][7]) == 40, "column heading limited to 40")
    pg.fill('#dmg-grid tr:nth-child(3) td.lab input', 'Y' * 20); pg.wait_for_timeout(200)
    ok(len(state(pg)['damageSheet']['rows'][1]['label']) == 15, "row heading limited to 15")
    # fill kit to its max, then add a 13th row: asked first
    km = (D['kitFit']['heightMm10'] - 12 * D['kitFit']['dmgRowMm10']) // D['kitFit']['kitRowMm10']
    for i in range(km - 10): pg.click('button[data-add=kit]')
    ok(pg.is_disabled('button[data-add=kit]') and len(state(pg)['kit']) == km, f"kit rows max {km} with 12 table rows")
    pg.fill('ol[data-list=kit] li:last-child [data-field=name]', 'Last kit'); pg.wait_for_timeout(200)
    dialogs.clear()
    pg.close(); pg, errs3 = open_page(b, dialogs=dialogs, accept=False)
    c = with_dmg(normal_char()); c['damageSheet']['rows'] = [{"label": f"R{i}", "values": [1, 2, 3]} for i in range(12)]
    c['kit'] = [{"name": f"K{i}", "count": 1} for i in range(km)]
    load(pg, {"app": "battleboard", "version": 1, "character": c})
    pg.click('details[data-sec=damageSheet] > summary')
    pg.click('[data-dmg=addRow]'); pg.wait_for_timeout(300)
    ok(len(dialogs) == 1 and len(state(pg)['damageSheet']['rows']) == 12, f"13th row would lose kit rows: asked, cancel keeps 12 ({dialogs})")
    pg.close(); dialogs.clear(); pg, errs4 = open_page(b, dialogs=dialogs, accept=True)
    load(pg, {"app": "battleboard", "version": 1, "character": c})
    pg.click('details[data-sec=damageSheet] > summary')
    pg.click('[data-dmg=addRow]'); pg.wait_for_timeout(300)
    st = state(pg); km13 = (D['kitFit']['heightMm10'] - 13 * D['kitFit']['dmgRowMm10']) // D['kitFit']['kitRowMm10']
    ok(len(st['damageSheet']['rows']) == 13 and len(st['kit']) == km13 and pg.is_disabled('[data-dmg=addRow]'), f"accept: 13 rows, kit trimmed to {km13}, add row disabled")
    ok(not pg.evaluate(GEOM), "13 rows + full kit: layout ok")
    dialogs.clear(); pg.click('#dmg-grid tr:nth-child(2) td.rx button'); pg.wait_for_timeout(300)
    ok(len(dialogs) == 1 and len(state(pg)['damageSheet']['rows']) == 12, "removing a filled row asks first")
    pg.click('#dmg-grid tr.h th:nth-child(2) button'); pg.wait_for_timeout(300)
    st = state(pg); ok(len(st['damageSheet']['columns']) == 2 and all(len(r['values']) == 2 for r in st['damageSheet']['rows']), "removing a column removes its values")
    pg.uncheck('[data-path="damageSheet.show"]'); pg.wait_for_timeout(300)
    st2 = state(pg); ok(st2['damageSheet']['show'] is False and st2['damageSheet']['rows'] == st['damageSheet']['rows'] and st2['kit'] == st['kit'], "switching off keeps everything")
    check(pg, "page switched off again", False, False)
    print("problems:", P or "none", "| js errors:", (errs + errs2 + errs3 + errs4) or "none")
    b.close()
